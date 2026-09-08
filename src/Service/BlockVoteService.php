<?php

namespace App\Service;

use App\Service\OsbbContacts;
use App\Entity\Account;
use App\Entity\AccountStatusLog;
use App\Entity\BlockVoteBallot;
use App\Entity\BlockVoteCampaign;
use App\Repository\AccountRepository;
use App\Repository\BlockVoteBallotRepository;
use App\Message\VoteBroadcastMessage;
use App\Repository\BlockVoteCampaignRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Community vote-to-block: admins open a campaign per candidate; every eligible voter
 * (active apartment account, candidate excluded) casts one ballot. YES > 30% of the
 * eligible snapshot blocks the candidate for 30 days (auto-unblock afterwards).
 *
 * Threshold denominator is frozen at campaign creation so a vote can't become
 * un-winnable just because new accounts were activated mid-vote.
 */
class BlockVoteService
{
    /** How long a campaign stays open before the cron tallies it. */
    public const VOTE_DAYS = 7;

    /** Duration of the block a passed campaign applies. */
    public const BLOCK_DAYS = 30;

    /**
     * Ballots a quiet question needs before it broadcasts itself.
     *
     * This is the half of the design that matters. Anybody may ask the house something and
     * it is visible at once; ringing 171 phones waits for an admin. Without this number
     * that would make the admins a gate, and an awkward question could be buried simply by
     * nobody ever approving it. With it, the house decides: a question fifteen neighbours
     * have already answered has proved it matters better than any approval could, and it
     * goes out on its own.
     *
     * Fifteen because it is roughly a tenth of who can vote — enough that one annoyed
     * person and two friends cannot trigger a broadcast, few enough to be reachable by a
     * question that genuinely interests people.
     */
    public const AUTO_BROADCAST_VOTES = 15;

    /** Longest a vote may run. Past a month nobody remembers what they were asked. */
    public const MAX_VOTE_DAYS = 30;

    /** How long before the deadline the one-shot last-day reminder fires (to non-voters). */
    public const FINAL_REMINDER_BEFORE_HOURS = 24;

    public function __construct(
        private EntityManagerInterface $em,
        private AccountRepository $accountRepository,
        private BlockVoteCampaignRepository $campaignRepository,
        private BlockVoteBallotRepository $ballotRepository,
        private AccountStatusAuditor $auditor,
        private Nutgram $bot,
        private LoggerInterface $logger,
        private DebtPolicy $debtPolicy,
        private PavilionPhotoService $photoService,
        private MessageBusInterface $bus,
        private ResidentChatService $residentChat,
        private DeepLink $links,
    ) {}

    private function now(): \DateTime
    {
        return new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
    }

    /**
     * Accounts entitled to vote: everyone who may book the pavilion — apartments AND parking,
     * regardless of is_active (debt/photo-blocked residents still get a voice). Only кладові
     * (storage) are excluded. The candidate is excluded. One account = one vote (DB constraint).
     *
     * **And only objects with somebody in the bot behind them.** This is the denominator of
     * the pass threshold, so every object counted here is a vote the campaign needs and can
     * never receive: nobody is on the other end to be notified, open the bot or press
     * anything. It did not matter while the bot held 175 objects that mostly had residents;
     * `objects:import-registry` then brought in the ЖК's whole register and the electorate
     * went from 112 to 774, of which roughly 180 have a linked resident. At 30% that is 233
     * votes needed out of ~180 possible — the mechanism could not pass, ever, and it took a
     * campaign nobody could win to notice, because the button still worked.
     *
     * A flat whose owner joins the bot tomorrow becomes a voter tomorrow; a campaign already
     * open keeps the count it was opened with, which is what `eligible_count` is for.
     *
     * @return Account[]
     */
    public function eligibleVoters(?Account $exclude = null): array
    {
        $out = [];
        foreach ($this->accountRepository->findAll() as $account) {
            /** @var Account $account */
            if (!self::mayVote($account)) {
                continue;
            }
            if ($exclude !== null && $account->getId() === $exclude->getId()) {
                continue;
            }
            $out[] = $account;
        }
        return $out;
    }

    /**
     * One definition, used for the roll call and for the ballot check, so the denominator
     * and the door cannot disagree — a voter who is counted but refused, or refused but
     * counted, is a campaign that adds up wrong.
     */
    public static function mayVote(Account $account): bool
    {
        return $account->canBookPavilion() && !$account->getUsers()->isEmpty();
    }

    public function isEligibleVoter(Account $voter, BlockVoteCampaign $campaign): bool
    {
        if (!self::mayVote($voter)) {
            return false;
        }
        // A question excludes nobody — there is no candidate to keep out of their own vote.
        return $campaign->getCandidate()?->getId() !== $voter->getId();
    }

    /**
     * A campaign accepts votes only while open AND before its deadline — defends against the
     * window between the deadline passing and the block-vote:tally cron closing the campaign,
     * so late votes can't flip a result after the period neighbours were told about.
     */
    public function isVotable(BlockVoteCampaign $campaign): bool
    {
        return $campaign->isOpen() && $campaign->getDeadlineAt() > $this->now();
    }

    /**
     * Open a campaign for a candidate and notify every eligible voter.
     *
     * @throws \RuntimeException when the candidate already has an open campaign.
     */
    /**
     * Put a question to the house.
     *
     * Same machinery as a block campaign — the eligible count is snapshotted, the deadline
     * is the same seven days, every voter is notified through the queue — and it ends by
     * simply closing: nothing is blocked, nothing is enacted, the counts go in the archive
     * and the ОСББ acts on them. Deliberately advisory. A bot that could enact a house
     * decision off a yes/no with no quorum would be a worse thing than no bot.
     */
    public function openQuestion(
        string $question,
        ?string $details,
        ?string $createdBy,
        ?TelegramUser $author = null,
        bool $broadcast = true,
        ?int $days = null,
    ): BlockVoteCampaign {
        $campaign = $this->openCampaign(null, $createdBy, BlockVoteCampaign::KIND_QUESTION, $broadcast, $days);
        $campaign->setQuestion($question);
        $campaign->setDetails($details);
        $campaign->setAuthor($author);
        $this->em->flush();

        if ($broadcast) {
            $this->broadcast($campaign);
        }

        return $campaign;
    }

    /**
     * Tell the house about a vote that has been sitting quietly: a DM to every voter and a
     * post in the chat.
     *
     * Idempotent — a second approval, or an approval racing the auto-promotion, must not
     * ring the same phones twice.
     */
    public function broadcast(BlockVoteCampaign $campaign): void
    {
        if ($campaign->isBroadcast() || !$campaign->isOpen()) {
            return;
        }

        $campaign->setBroadcastAt($this->now());
        $this->em->flush();

        $this->announce($campaign);

        foreach ($this->eligibleVoters($campaign->getCandidate()) as $voter) {
            /** @var Account $voter */
            $this->bus->dispatch(new VoteBroadcastMessage($campaign->getId(), (int)$voter->getId()));
        }

        $this->logger->info('block-vote: broadcast', [
            'campaign_id' => $campaign->getId(),
            'author_id' => $campaign->getAuthor()?->getId(),
        ]);
    }

    /** A resident's live question, if they have one — one open per account. */
    public function openQuestionOf(?Account $account): ?BlockVoteCampaign
    {
        if (!$account instanceof Account) {
            return null;
        }

        foreach ($this->campaignRepository->findOpen() as $campaign) {
            if ($campaign->getAuthor()?->getAccount()?->getId() === $account->getId()) {
                return $campaign;
            }
        }

        return null;
    }

    public function openCampaign(
        ?Account $candidate,
        ?string $createdBy,
        string $kind = BlockVoteCampaign::KIND_BLOCK,
        bool $broadcast = true,
        ?int $days = null,
    ): BlockVoteCampaign
    {
        // Only a block campaign is one-per-candidate; two questions can sensibly run at
        // once, and there is no candidate to collide on anyway.
        if ($candidate !== null && $this->campaignRepository->findOpenForCandidate($candidate) !== null) {
            throw new \RuntimeException('Для цього аккаунта вже відкрите голосування.');
        }

        $voters = $this->eligibleVoters($candidate);

        $campaign = (new BlockVoteCampaign())
            ->setKind($kind)
            ->setCandidate($candidate)
            ->setStatus(BlockVoteCampaign::STATUS_OPEN)
            ->setEligibleCount(count($voters))
            // A block campaign is always VOTE_DAYS: it is an accusation, and leaving one
            // hanging over a household longer than a week is a punishment of its own.
            // A question may reasonably run longer — people are away, and «чи ставимо
            // шлагбаум» is not urgent.
            ->setDeadlineAt((clone $this->now())->modify(
                '+' . max(1, min($days ?? self::VOTE_DAYS, self::MAX_VOTE_DAYS)) . ' days'
            ))
            ->setCreatedBy($createdBy)
            // Stamped up front for an admin-opened campaign; a resident's question stays
            // quiet until somebody approves it or it earns the push itself.
            ->setBroadcastAt($broadcast ? $this->now() : null);

        $this->em->persist($campaign);
        $this->em->flush();

        if ($broadcast) {
            // Hand the broadcast off to the async (Doctrine) transport — one message per
            // voter, each independently retryable — so the request returns instantly
            // instead of blocking on hundreds of sequential Telegram sends. The
            // city-park-messenger systemd worker delivers them.
            foreach ($voters as $voter) {
                /** @var Account $voter */
                $this->bus->dispatch(new VoteBroadcastMessage($campaign->getId(), (int)$voter->getId()));
            }

            $this->announce($campaign);
        }

        $this->logger->info('block-vote: campaign opened', [
            'campaign_id' => $campaign->getId(),
            'kind' => $kind,
            'candidate_account_id' => $candidate?->getId(),
            'candidate_account_number' => $candidate?->getAccountNumber(),
            'eligible_count' => $campaign->getEligibleCount(),
            'yes_needed' => $campaign->yesNeeded(),
            'created_by' => $createdBy,
        ]);

        return $campaign;
    }

    /**
     * Record (or change) a voter's ballot, then tally and block if the threshold is crossed.
     *
     * @return array{recorded:bool, changed:bool, passed:bool, yes:int, no:int}
     */
    public function recordVote(
        BlockVoteCampaign $campaign,
        Account $voter,
        bool $value,
        ?TelegramUser $castBy = null,
    ): array {
        $ballot = $this->ballotRepository->findOneByCampaignAndVoter($campaign, $voter);

        // **A vote is final.** It used to be changeable until the deadline, which is a
        // reasonable rule for an anonymous poll and a bad one for a recorded ballot: the
        // panel now shows which household voted how, and a record that can be rewritten
        // until the last minute is not a record. It also removes the shape where somebody
        // watches the tally and flips at the end.
        if ($ballot !== null) {
            $tally = $this->ballotRepository->tally($campaign);

            return [
                'recorded' => false,
                'already'  => true,
                'changed'  => false,
                'passed'   => false,
                'value'    => $ballot->getValue(),
                'yes'      => $tally['yes'],
                'no'       => $tally['no'],
            ];
        }

        $changed = false;

        if ($ballot === null) {
            $ballot = (new BlockVoteBallot())
                ->setCampaign($campaign)
                ->setVoterAccount($voter)
                ->setVoterUser($castBy)
                ->setCastAt($this->now())
                ->setValue($value);
            $this->em->persist($ballot);
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                // Two family members of the same account voted near-simultaneously; the unique
                // index (campaign, voter_account) rejected this second insert. The flush closed
                // the EM — clear it and let the sibling's ballot stand (one account = one vote).
                // Re-fetch the campaign so the tally below runs on a managed entity.
                $this->em->clear();
                $campaign = $this->campaignRepository->find($campaign->getId());
            }
        }

        $tally = $this->ballotRepository->tally($campaign);
        $passed = false;

        // A question has no threshold to cross: it closes at its deadline and nothing
        // happens to anybody. Only a block campaign can end early, the moment the vote it
        // needs has been cast.
        // A quiet question that neighbours are answering anyway has proved it matters
        // better than an approval could. Checked on the total, not on «за»: interest is
        // interest, and a question fifteen people voted *against* is exactly as worth
        // putting in front of the house as one they voted for.
        if ($campaign->isQuestion()
            && !$campaign->isBroadcast()
            && ($tally['yes'] + $tally['no']) >= self::AUTO_BROADCAST_VOTES
        ) {
            $this->broadcast($campaign);
        }

        if ($campaign->isBlock() && $tally['yes'] >= $campaign->yesNeeded()) {
            $this->closeCampaign($campaign, BlockVoteCampaign::STATUS_PASSED, $tally);
            $this->applyBlock($campaign);
            $passed = true;
        }

        return [
            'recorded' => true,
            'changed'  => $changed,
            'passed'   => $passed,
            'yes'      => $tally['yes'],
            'no'       => $tally['no'],
        ];
    }

    /**
     * Close every expired open campaign (deadline passed): pass+block if YES crossed the
     * threshold, otherwise fail. Returns the number of campaigns that resulted in a block.
     */
    public function closeExpiredCampaigns(): int
    {
        $blocked = 0;
        foreach ($this->campaignRepository->findExpiredOpen($this->now()) as $campaign) {
            $tally = $this->ballotRepository->tally($campaign);

            // A question is recorded, not judged. «Рішення прийнято» off three ballots out
            // of a hundred and eighty would be the bot inventing a mandate nobody gave it,
            // so the result is the counts and the ОСББ reads them.
            if ($campaign->isQuestion()) {
                $this->closeCampaign($campaign, BlockVoteCampaign::STATUS_CLOSED, $tally);
                $this->logger->info('block-vote: question closed at deadline', [
                    'campaign_id' => $campaign->getId(),
                    'yes' => $tally['yes'], 'no' => $tally['no'],
                ]);

                continue;
            }

            if ($tally['yes'] >= $campaign->yesNeeded()) {
                $this->closeCampaign($campaign, BlockVoteCampaign::STATUS_PASSED, $tally);
                $this->applyBlock($campaign);
                $blocked++;
            } else {
                $this->closeCampaign($campaign, BlockVoteCampaign::STATUS_FAILED, $tally);
                $this->logger->info('block-vote: campaign failed at deadline', [
                    'campaign_id' => $campaign->getId(),
                    'yes' => $tally['yes'], 'no' => $tally['no'],
                    'yes_needed' => $campaign->yesNeeded(),
                ]);
            }
        }
        return $blocked;
    }

    public function cancelCampaign(BlockVoteCampaign $campaign): void
    {
        if (!$campaign->isOpen()) {
            return;
        }
        $tally = $this->ballotRepository->tally($campaign);
        $this->closeCampaign($campaign, BlockVoteCampaign::STATUS_CANCELLED, $tally);
        $this->logger->info('block-vote: campaign cancelled', ['campaign_id' => $campaign->getId()]);
    }

    private function closeCampaign(BlockVoteCampaign $campaign, string $status, array $tally): void
    {
        $campaign->setStatus($status)
            ->setClosedAt($this->now())
            ->setResultYes($tally['yes'])
            ->setResultNo($tally['no']);
        $this->em->flush();

        $this->announce($campaign);
    }

    /**
     * The vote in the residents' chat — posted when it opens, and the same message edited
     * when it closes.
     *
     * Edited, not re-posted: a thread carrying «відкрито голосування» and no ending is how
     * the same question comes back next spring, and `editMessageText` has none of
     * `deleteMessage`'s 48-hour limit while a vote runs for seven days. It also keeps the
     * announcement where the discussion under it is.
     *
     * The DM broadcast is not replaced by this. A DM reaches the people who may vote; the
     * post is what makes the vote a thing the house can see happening, and what carries the
     * result to everyone afterwards — including the flats with nobody in the bot, who
     * cannot vote but live here.
     *
     * Never fatal, and silent when no topic is configured: an unreachable chat must not
     * stop a vote from opening.
     */
    private function announce(BlockVoteCampaign $campaign): void
    {
        $topic = $this->residentChat->topic(ResidentChatService::TOPIC_VOTES);

        if (!$this->residentChat->isConfigured() || $topic === null) {
            return;
        }

        $text = $this->chatPost($campaign);
        $markup = $this->links->button(
            DeepLink::KIND_VOTE,
            $campaign->getId(),
            '↗️ Проголосувати в боті',
        );
        $chatId = (int)$this->residentChat->chatId();

        if ($campaign->getChatMessageId() !== null) {
            try {
                $this->bot->editMessageText(
                    text: $text,
                    chat_id: $chatId,
                    message_id: $campaign->getChatMessageId(),
                    parse_mode: ParseMode::HTML,
                    // The button goes when the vote does: a live «Проголосувати» under a
                    // closed vote is the one thing worse than no button.
                    reply_markup: $campaign->isOpen() ? $markup : null,
                );

                return;
            } catch (\Throwable $e) {
                $this->logger->info('block-vote: chat post not edited', [
                    'campaign_id' => $campaign->getId(),
                    'error' => $e->getMessage(),
                ]);

                return;
            }
        }

        try {
            $message = $this->bot->sendMessage(
                text: $text,
                chat_id: $chatId,
                message_thread_id: $topic,
                parse_mode: ParseMode::HTML,
                reply_markup: $markup,
            );

            $campaign->setChatMessageId($message?->message_id);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('block-vote: chat announcement failed', [
                'campaign_id' => $campaign->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** What the chat sees: the question while it runs, the result once it is over. */
    public function chatPost(BlockVoteCampaign $campaign): string
    {
        $yes = (int)($campaign->getResultYes() ?? $this->ballotRepository->tally($campaign)['yes']);
        $no = (int)($campaign->getResultNo() ?? $this->ballotRepository->tally($campaign)['no']);
        $subject = htmlspecialchars($this->subjectLabel($campaign), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($campaign->isOpen()) {
            $details = $campaign->getDetails();

            return sprintf(
                "🗳 <b>%s</b>\n%s\n%s\n🗓 Голосування до <b>%s</b>\n\n<i>%s</i>",
                $campaign->isQuestion() ? 'Питання до мешканців' : 'Голосування за блокування',
                $subject,
                $details !== null
                    ? '<i>' . htmlspecialchars($details, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</i>'
                    : '',
                $campaign->getDeadlineAt()->format('d.m.Y'),
                $campaign->isQuestion()
                    ? 'Рішення ухвалює ОСББ — результат покаже, чого хочуть мешканці.'
                    : 'Голосують власники квартир і паркомісць, один голос від рахунку.',
            );
        }

        return sprintf(
            "🗳 <b>Голосування завершено</b>\n%s\n\n📊 За: <b>%d</b> · Проти: <b>%d</b>\n<i>%s</i>",
            $subject,
            $yes,
            $no,
            match ($campaign->getStatus()) {
                BlockVoteCampaign::STATUS_PASSED => 'Рішення: заблокувати на ' . self::BLOCK_DAYS . ' днів.',
                BlockVoteCampaign::STATUS_FAILED => 'Рішення: не блокувати — голосів не вистачило.',
                BlockVoteCampaign::STATUS_CANCELLED => 'Голосування скасовано.',
                default => 'Це опитування — рішення ухвалює ОСББ, спираючись на цей результат.',
            },
        );
    }

    /**
     * Block the candidate for 30 days, audit the transition, and notify their users.
     */
    private function applyBlock(BlockVoteCampaign $campaign): void
    {
        $account = $campaign->getCandidate();

        // Belt and braces: a question can never reach here (recordVote and
        // closeExpiredCampaigns both check the kind first), and if one ever did, blocking
        // nobody must not become blocking somebody at random.
        if (!$account instanceof Account || !$campaign->isBlock()) {
            return;
        }
        $until = (clone $this->now())->modify('+' . self::BLOCK_DAYS . ' days');

        // Every passed campaign counts as a community-block decision against this account,
        // even if it was already blocked for another reason at the time.
        $account->incrementVoteBlockCount();
        $count = $account->getVoteBlockCount();

        if (!$account->isActive()) {
            // Already blocked by another path (debt / photo / admin). Still stamp the 30-day
            // vote window so the block can't evaporate the moment that other reason clears
            // (e.g. a photo upload or debt payment). autoUnblockExpired() re-checks debt/photo
            // before restoring access, so this never lifts a still-valid debt/photo block early.
            $account->setBlockedUntil($until);
            $this->em->flush();
            $this->logger->info('block-vote: candidate already blocked, vote window stamped', [
                'campaign_id' => $campaign->getId(),
                'account_id' => $account->getId(),
                'blocked_until' => $until->format('Y-m-d H:i'),
                'vote_block_count' => $count,
            ]);
            $this->broadcastToAccount($account, $this->blockText($until, $count));
            return;
        }

        $account->setIsActive(false);
        $account->setBlockedUntil($until);

        $this->auditor->log(
            $account, true, false,
            AccountStatusLog::SOURCE_COMMUNITY_VOTE,
            'vote',
            sprintf(
                'campaign=%d yes=%d/%d eligible=%d until=%s count=%d',
                $campaign->getId(),
                $campaign->getResultYes() ?? 0,
                $campaign->yesNeeded(),
                $campaign->getEligibleCount(),
                $until->format('Y-m-d'),
                $count
            ),
            'system',
        );
        $this->em->flush();

        $this->logger->info('block-vote: candidate blocked by community vote', [
            'campaign_id' => $campaign->getId(),
            'account_id' => $account->getId(),
            'blocked_until' => $until->format('Y-m-d H:i'),
            'vote_block_count' => $count,
        ]);

        $this->broadcastToAccount($account, $this->blockText($until, $count));
    }

    private function blockText(\DateTime $until, int $count): string
    {
        return sprintf(
            "⛔ <b>Ваш аккаунт заблоковано рішенням спільноти</b>\n\n"
            . "Сусіди проголосували за тимчасове блокування. Доступ до бронювання припинено до <b>%s</b> (30 днів).\n\n"
            . "Це вже <b>%d-е</b> блокування вашого аккаунта за рішенням спільноти.\n\n"
            . "Після цієї дати доступ відновиться автоматично.\n\n"
            . OsbbContacts::askThem('Питання:'),
            $until->format('d.m.Y'),
            $count
        );
    }

    /**
     * Auto-unblock accounts whose 30-day vote-block has elapsed. The window (blocked_until)
     * is always cleared on expiry, but access is only restored when no OTHER reason still
     * blocks the account — debt over threshold or a standing photo block keep it down, exactly
     * as every other unblock path enforces. This prevents the vote-expiry from lifting a
     * debt/photo block that happened to accrue during the 30 days.
     *
     * @return int number of accounts unblocked
     */
    public function autoUnblockExpired(): int
    {
        $now = $this->now();
        $unblocked = 0;

        foreach ($this->accountRepository->findAll() as $account) {
            /** @var Account $account */
            $until = $account->getBlockedUntil();
            if ($until === null || $until > $now) {
                continue;
            }

            $wasActive = $account->isActive();
            $account->setBlockedUntil(null);

            $keptByDebt  = $this->debtPolicy->isAccountBlocked($account);
            $keptByPhoto = $this->photoService->hasOpenBlockingRequest($account);

            if (!$wasActive && ($keptByDebt || $keptByPhoto)) {
                $this->logger->info('block-vote: vote window expired but account kept blocked by other reason', [
                    'account_id' => $account->getId(),
                    'debt' => $keptByDebt, 'photo' => $keptByPhoto,
                ]);
                $this->em->flush();
                continue;
            }

            if (!$wasActive) {
                $account->setIsActive(true);
                $this->auditor->log(
                    $account, false, true,
                    AccountStatusLog::SOURCE_VOTE_AUTO_UNBLOCK,
                    'vote',
                    'community vote-block expired',
                    'system',
                );
                $unblocked++;
                $this->broadcastToAccount(
                    $account,
                    sprintf(
                        "✅ <b>Доступ до бронювання відновлено.</b>\n\n"
                        . "Термін блокування за рішенням спільноти завершився. Можна знову бронювати.\n\n"
                        . "<i>Всього блокувань за рішенням спільноти: %d. Будь ласка, дотримуйтесь правил, щоб уникнути повторних голосувань.</i>",
                        $account->getVoteBlockCount()
                    )
                );
            }

            $this->em->flush();
        }

        if ($unblocked > 0) {
            $this->logger->info('block-vote: auto-unblocked expired vote-blocks', ['count' => $unblocked]);
        }

        return $unblocked;
    }

    /**
     * Human label for a candidate: street + house + unit (privacy: no personal name).
     * The house matters — "кв. 109" alone is ambiguous across буд., so voters must see which.
     */
    /**
     * What this vote is about, in one line — a flat on trial or the question itself.
     *
     * One method so every surface (the menu, the archive, the broadcast, the admin table)
     * says the same thing about the same campaign; the block half already had this problem
     * solved and the question half would otherwise grow its own copy.
     */
    public function subjectLabel(BlockVoteCampaign $campaign): string
    {
        return $campaign->isQuestion()
            ? (string)$campaign->getQuestion()
            : $this->candidateLabel($campaign->getCandidate());
    }

    public function candidateLabel(?Account $account): string
    {
        if (!$account instanceof Account) {
            return 'невідомий об’єкт';
        }

        $num = trim((string)$account->getApartmentNumber());
        $unit = $account->isParking()
            ? ($num !== '' ? 'паркомісце ' . $num : 'паркомісце')
            : ($num !== '' ? 'кв. ' . $num : ('аккаунт ' . $account->getAccountNumber()));

        $addr = trim(trim((string)$account->getStreet()) . ' ' . trim((string)$account->getHouseNumber()));

        return $addr !== '' ? $addr . ', ' . $unit : $unit;
    }

    /**
     * Deliver the "campaign opened" notice to one voter account. Called by the async
     * VoteBroadcastMessage handler (one message per voter). Skips silently if the campaign
     * was cancelled/closed before delivery, or the account vanished.
     */
    public function deliverOpenedNotice(int $campaignId, int $accountId, bool $reminder = false): void
    {
        $campaign = $this->campaignRepository->find($campaignId);
        if ($campaign === null || !$campaign->isOpen()) {
            return;
        }
        $account = $this->accountRepository->find($accountId);
        if ($account === null) {
            return;
        }
        $this->broadcastToAccount($account, $reminder ? $this->reminderText($campaign) : $this->openedText($campaign));
    }

    /**
     * Re-dispatch the notice to every eligible voter who has NOT voted yet — catches anyone
     * the original broadcast missed and nudges procrastinators, without re-pinging those who
     * already voted. Goes through the async worker (one retryable message per recipient).
     *
     * @return int number of reminder messages dispatched
     */
    public function dispatchReminders(BlockVoteCampaign $campaign): int
    {
        if (!$campaign->isOpen()) {
            return 0;
        }
        $voted = array_flip($this->ballotRepository->votedAccountIds($campaign));
        $count = 0;
        foreach ($this->eligibleVoters($campaign->getCandidate()) as $voter) {
            /** @var Account $voter */
            if (isset($voted[(int)$voter->getId()])) {
                continue;
            }
            $this->bus->dispatch(new VoteBroadcastMessage($campaign->getId(), (int)$voter->getId(), true));
            $count++;
        }
        $this->logger->info('block-vote: reminders dispatched', [
            'campaign_id' => $campaign->getId(),
            'dispatched' => $count,
        ]);
        return $count;
    }

    /**
     * One-shot last-day reminder: for every open campaign whose deadline is within the next
     * FINAL_REMINDER_BEFORE_HOURS, re-send (async) to non-voters once and stamp
     * final_reminder_sent_at so the hourly cron never repeats it. Residents thus see at most
     * two notices total: at open, and on the final day if they still haven't voted.
     *
     * @return int total reminder messages dispatched across campaigns
     */
    public function sendDueFinalReminders(): int
    {
        $now = $this->now();
        $soon = (clone $now)->modify('+' . self::FINAL_REMINDER_BEFORE_HOURS . ' hours');

        $sent = 0;
        foreach ($this->campaignRepository->findDueFinalReminder($now, $soon) as $campaign) {
            $n = $this->dispatchReminders($campaign);
            $campaign->setFinalReminderSentAt($now);
            $this->em->flush();
            $sent += $n;
            $this->logger->info('block-vote: final-day reminder sent', [
                'campaign_id' => $campaign->getId(),
                'dispatched' => $n,
            ]);
        }
        return $sent;
    }

    private function openedText(BlockVoteCampaign $campaign): string
    {
        return "🗳️ <b>Відкрито голосування спільноти</b>\n\n" . $this->noticeBody($campaign);
    }

    private function reminderText(BlockVoteCampaign $campaign): string
    {
        return "📣 <b>Нагадування: триває голосування спільноти</b>\nВи ще <b>не проголосували</b>.\n\n"
            . $this->noticeBody($campaign);
    }

    /**
     * Shared body for the opened / reminder notices.
     *
     * Two shapes, because the two kinds of vote promise different things. A block campaign
     * has to say what crossing the threshold does to a named household, and how many votes
     * that takes. A question has none of that: it decides nothing on its own, and printing
     * «потрібно 55 голосів» beside it would be a promise the bot cannot keep — so it says
     * plainly that the ОСББ will read the result.
     */
    private function noticeBody(BlockVoteCampaign $campaign): string
    {
        $tally = $this->ballotRepository->tally($campaign);

        if ($campaign->isQuestion()) {
            $details = $campaign->getDetails();

            return sprintf(
                "<b>%s</b>\n%s\n📊 Зараз: «За» <b>%d</b> · «Проти» <b>%d</b> (мешканців з правом голосу: %d)\n"
                . "🗓 До: <b>%s</b>\n\n"
                . "Один аккаунт — один голос; свій вибір можна змінити до завершення.\n"
                . "<i>Це опитування: рішення ухвалює ОСББ, а результат голосування — те, на що воно спиратиметься.</i>\n"
                . "👉 Проголосувати: меню «🗳️ Голосування» або команда /vote.",
                htmlspecialchars((string)$campaign->getQuestion(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $details !== null
                    ? '<i>' . htmlspecialchars($details, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</i>\n\n"
                    : "\n",
                $tally['yes'],
                $tally['no'],
                $campaign->getEligibleCount(),
                $campaign->getDeadlineAt()->format('d.m.Y'),
            );
        }

        return sprintf(
            "Пропонується тимчасово заблокувати: <b>%s</b>.\n\n"
            . "📊 Зараз: «За» <b>%d</b> · «Проти» <b>%d</b>\n"
            . "Треба «За»: <b>%d</b> з %d\n"
            . "🗓 До: <b>%s</b>\n\n"
            . "Якщо «За» набере понад 30%%, аккаунт буде <b>заблоковано на %d днів</b> — бронювання альтанок стане недоступним. Після цього строку доступ відновиться <b>автоматично</b>.\n\n"
            . "Один аккаунт — один голос; свій вибір можна змінити до завершення.\n"
            . "👉 Проголосувати: меню «🗳️ Голосування» або команда /vote.",
            $this->candidateLabel($campaign->getCandidate()),
            $tally['yes'],
            $tally['no'],
            $campaign->yesNeeded(),
            $campaign->getEligibleCount(),
            $campaign->getDeadlineAt()->format('d.m.Y'),
            self::BLOCK_DAYS,
        );
    }

    /**
     * Send a message to every TelegramUser of an account, skipping those without a chat_id
     * and swallowing per-user send errors so one offline member can't fail the batch.
     */
    private function broadcastToAccount(Account $account, string $text): void
    {
        foreach ($account->getUsers() as $user) {
            if (!$user->getChatId()) {
                continue;
            }
            try {
                $this->bot->sendMessage(
                    text: $text,
                    chat_id: $user->getChatId(),
                    parse_mode: ParseMode::HTML,
                );
            } catch (\Throwable $t) {
                $this->logger->warning('block-vote: notify failed', [
                    'account_id' => $account->getId(),
                    'user_id' => $user->getId(),
                    'error' => $t->getMessage(),
                ]);
            }
        }
    }
}
