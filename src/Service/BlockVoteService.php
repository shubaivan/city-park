<?php

namespace App\Service;

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
 * (active apartment account, candidate excluded) casts one ballot. YES > 50% of the
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
     * @return Account[]
     */
    public function eligibleVoters(?Account $exclude = null): array
    {
        $out = [];
        foreach ($this->accountRepository->findAll() as $account) {
            /** @var Account $account */
            if (!$account->canBookPavilion()) {
                continue;
            }
            if ($exclude !== null && $account->getId() === $exclude->getId()) {
                continue;
            }
            $out[] = $account;
        }
        return $out;
    }

    public function isEligibleVoter(Account $voter, BlockVoteCampaign $campaign): bool
    {
        if (!$voter->canBookPavilion()) {
            return false;
        }
        return $voter->getId() !== $campaign->getCandidate()->getId();
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
    public function openCampaign(Account $candidate, ?string $createdBy): BlockVoteCampaign
    {
        if ($this->campaignRepository->findOpenForCandidate($candidate) !== null) {
            throw new \RuntimeException('Для цього аккаунта вже відкрите голосування.');
        }

        $voters = $this->eligibleVoters($candidate);

        $campaign = (new BlockVoteCampaign())
            ->setCandidate($candidate)
            ->setStatus(BlockVoteCampaign::STATUS_OPEN)
            ->setEligibleCount(count($voters))
            ->setDeadlineAt((clone $this->now())->modify('+' . self::VOTE_DAYS . ' days'))
            ->setCreatedBy($createdBy);

        $this->em->persist($campaign);
        $this->em->flush();

        // Hand the broadcast off to the async (Doctrine) transport — one message per voter,
        // each independently retryable — so the admin's "open vote" request returns instantly
        // instead of blocking on ~hundreds of sequential Telegram sends. The city-park-messenger
        // systemd worker delivers them.
        foreach ($voters as $voter) {
            /** @var Account $voter */
            $this->bus->dispatch(new VoteBroadcastMessage($campaign->getId(), (int)$voter->getId()));
        }

        $this->logger->info('block-vote: campaign opened', [
            'campaign_id' => $campaign->getId(),
            'candidate_account_id' => $candidate->getId(),
            'candidate_account_number' => $candidate->getAccountNumber(),
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
    public function recordVote(BlockVoteCampaign $campaign, Account $voter, bool $value): array
    {
        $ballot = $this->ballotRepository->findOneByCampaignAndVoter($campaign, $voter);

        $changed = false;
        if ($ballot === null) {
            $ballot = (new BlockVoteBallot())
                ->setCampaign($campaign)
                ->setVoterAccount($voter)
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
        } elseif ($ballot->getValue() !== $value) {
            $ballot->setValue($value);
            $changed = true;
            $this->em->flush();
        }

        $tally = $this->ballotRepository->tally($campaign);
        $passed = false;

        if ($tally['yes'] >= $campaign->yesNeeded()) {
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
    }

    /**
     * Block the candidate for 30 days, audit the transition, and notify their users.
     */
    private function applyBlock(BlockVoteCampaign $campaign): void
    {
        $account = $campaign->getCandidate();
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
            . "Питання — Аліна Бухгалтер (+380 93 658 32 02) або голова ОСББ Люда (+380 67 470 46 24).",
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
    public function candidateLabel(Account $account): string
    {
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
     * Shared detailed body for the opened/reminder notices: who, current tally, how many "За"
     * are needed, the deadline, and what happens to the candidate if it passes.
     */
    private function noticeBody(BlockVoteCampaign $campaign): string
    {
        $tally = $this->ballotRepository->tally($campaign);
        return sprintf(
            "Пропонується тимчасово заблокувати: <b>%s</b>.\n\n"
            . "📊 Зараз: «За» <b>%d</b> · «Проти» <b>%d</b>\n"
            . "✅ Щоб ухвалити рішення, потрібно «За»: <b>%d</b> з %d квартир та паркомісць (понад половину).\n"
            . "⏳ Голосування триває до <b>%s</b>.\n\n"
            . "Якщо «За» набере більшість, аккаунт буде <b>заблоковано на %d днів</b> — бронювання альтанок стане недоступним. Після цього строку доступ відновиться <b>автоматично</b>.\n\n"
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
