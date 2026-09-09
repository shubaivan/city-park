<?php

namespace App\Telegram\Voting\Command;

use App\Service\OsbbContacts;
use App\Entity\Account;
use App\Entity\BlockVoteCampaign;
use App\Repository\BlockVoteBallotRepository;
use App\Repository\BlockVoteCampaignRepository;
use App\Service\BlockVoteService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * "🗳️ Голосування" menu: lists open vote-to-block campaigns the current account may vote
 * on and lets it cast / change a single ballot per campaign. Callbacks:
 *   voting-menu                — render the list
 *   bvote:<campaignId>:yes|no  — cast / change a vote, then re-render
 */
class VotingMenuCommand
{
    public const MENU_CALLBACK = 'voting-menu';

    /** Finished votes: what the house decided, and how it split. */
    public const PAST_CALLBACK = 'voting-past';

    /**
     * Re-read the tally without leaving the message.
     *
     * The count in this message is a snapshot taken when it was drawn, and a vote runs for
     * a week — so the numbers a resident is looking at are as old as the last thing they
     * tapped. The only way to refresh them was «На головну» and back in, which redraws the
     * whole menu somewhere further down the chat. Same button, and the same reason, as the
     * guard's board.
     */
    public const REFRESH_CALLBACK = 'voting-refresh';

    /** The «you voted» pill is a label, not a button — it answers with nothing. */
    private const NOOP_CALLBACK = 'vote:noop';

    public function __construct(
        private TelegramUserService $telegramUserService,
        private BlockVoteService $voteService,
        private BlockVoteCampaignRepository $campaignRepository,
        private BlockVoteBallotRepository $ballotRepository,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->isCallbackQuery() ? ($bot->callbackQuery()->data ?? '') : '';

        if ($data === self::NOOP_CALLBACK) {
            $bot->answerCallbackQuery();
            return;
        }

        if ($data === self::PAST_CALLBACK) {
            $this->renderArchive($bot);
            return;
        }

        if ($data === self::REFRESH_CALLBACK) {
            $this->renderMenu($bot, edit: true, refreshing: true);
            return;
        }

        if (str_starts_with($data, 'vote:drop:')) {
            $this->dropOwn($bot, (int)substr($data, strlen('vote:drop:')));
            return;
        }

        if (str_starts_with($data, 'bvote:')) {
            $this->castVote($bot, $data);
            return;
        }

        $this->renderMenu($bot, edit: $bot->isCallbackQuery());
    }

    private function currentAccount(Nutgram $bot): ?Account
    {
        $user = $this->telegramUserService->getCurrentUser();
        if (!$user) {
            return null;
        }
        return $this->telegramUserService->resolveAccount($user);
    }

    /**
     * Somebody tapped «↗️ Проголосувати в боті» under the post in the residents' chat.
     *
     * The open list, not the campaign in isolation: a vote is cast from the menu and the
     * menu already shows every vote this person may cast, marked with how they voted. When
     * the one they came for is over, the archive is where it went — and renderMenu()
     * already offers that row, so they land one tap from the answer rather than on an
     * error.
     */
    public function openFromDeepLink(Nutgram $bot, int $campaignId): void
    {
        $this->renderMenu($bot, edit: false);
    }

    private function renderMenu(Nutgram $bot, bool $edit, ?string $notice = null, bool $refreshing = false): void
    {
        $account = $this->currentAccount($bot);

        if (!$account) {
            $this->respond(
                $bot,
                $edit,
                "🗳️ <b>Голосування</b>\n\nВаш акаунт не підтверджений ОСББ — голосування недоступне.\n"
                . "Зв'яжіться з бухгалтером ОСББ:\n" . OsbbContacts::ACCOUNTANT_LINE,
                InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton())
            );
            return;
        }

        if (!$account->canBookPavilion()) {
            $this->respond(
                $bot,
                $edit,
                "🗳️ <b>Голосування</b>\n\nГолосувати можуть власники квартир та паркомісць. Для кладових голосування недоступне.",
                InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton())
            );
            return;
        }

        // Only votable campaigns (open + before deadline) this account may vote on.
        $campaigns = array_values(array_filter(
            $this->campaignRepository->findOpen(),
            fn(BlockVoteCampaign $c) => $this->voteService->isVotable($c)
                && $this->voteService->isEligibleVoter($account, $c)
        ));

        if (!$campaigns) {
            $this->respond(
                $bot,
                $edit,
                ($notice ? $notice . "\n\n" : '')
                . "🗳️ <b>Голосування</b>\n\nНаразі немає відкритих голосувань.",
                $this->withArchive($this->withAsk($this->withRefresh(InlineKeyboardMarkup::make()), $account))
                    ->addRow(StartCommand::homeButton()),
                $refreshing,
            );
            return;
        }

        $lines = [];
        if ($notice) {
            $lines[] = $notice;
            $lines[] = '';
        }
        $lines[] = '🗳️ <b>Голосування</b>';
        $lines[] = '';
        $lines[] = 'Один акаунт — один голос. Голос остаточний: змінити його не можна.';
        $lines[] = '';

        $markup = InlineKeyboardMarkup::make();

        foreach ($campaigns as $campaign) {
            $tally = $this->ballotRepository->tally($campaign);
            $ballot = $this->ballotRepository->findOneByCampaignAndVoter($campaign, $account);
            $mine = $ballot === null ? null : $ballot->getValue();

            $voted = $mine === null ? '' : ($mine ? "\n<i>Ваш голос: За</i>" : "\n<i>Ваш голос: Проти</i>");

            if ($campaign->isQuestion()) {
                // No «треба N»: a question crosses no line and enacts nothing, so a target
                // beside it would be a promise the bot cannot keep. What it does say is
                // who decides — otherwise a resident reasonably reads a vote as binding.
                $details = $campaign->getDetails();

                $lines[] = sprintf(
                    "❓ <b>%s</b>%s\nЗа: <b>%d</b> · Проти: <b>%d</b>\n🗓 %s — <b>%s</b>%s",
                    self::esc((string)$campaign->getQuestion()),
                    $details !== null ? "\n<i>" . self::esc($details) . '</i>' : '',
                    $tally['yes'],
                    $tally['no'],
                    // Started and ends: «до 15.09» alone leaves a reader unable to tell a
                    // vote opened this morning from one that has been sitting a week with
                    // three ballots on it, and those call for different urgency.
                    $campaign->getCreatedAt()?->format('d.m') ?? '—',
                    $campaign->getDeadlineAt()->format('d.m.Y'),
                    $voted,
                );
                $lines[] = '<i>Рішення ухвалює ОСББ — результат голосування покаже, чого хочуть мешканці.</i>';
                $lines[] = '';

                $id = $campaign->getId();
                // Once cast, the row becomes a statement rather than a choice: a live
                // button under a final vote invites a tap that can only be refused.
                $markup->addRow($mine === null
                    ? InlineKeyboardButton::make('👍 За', callback_data: 'bvote:' . $id . ':yes')
                    : InlineKeyboardButton::make(
                        $mine ? '✅ Ви проголосували: За' : '✅ Ви проголосували: Проти',
                        callback_data: self::NOOP_CALLBACK,
                    ),
                    ...($mine === null
                        ? [InlineKeyboardButton::make('👎 Проти', callback_data: 'bvote:' . $id . ':no')]
                        : []),
                );

                continue;
            }

            $priorBlocks = $campaign->getCandidate()?->getVoteBlockCount() ?? 0;
            $lines[] = sprintf(
                "👤 <b>%s</b>%s\nЗа: <b>%d</b> · Проти: <b>%d</b> · Треба «За»: <b>%d</b> з %d\n🗓 %s — <b>%s</b>%s",
                $this->voteService->candidateLabel($campaign->getCandidate()),
                $priorBlocks > 0 ? sprintf("\n<i>раніше блокувався за рішенням спільноти: %d раз(и)</i>", $priorBlocks) : '',
                $tally['yes'],
                $tally['no'],
                $campaign->yesNeeded(),
                $campaign->getEligibleCount(),
                $campaign->getCreatedAt()?->format('d.m') ?? '—',
                $campaign->getDeadlineAt()->format('d.m.Y'),
                $voted,
            );
            $lines[] = '';

            $id = $campaign->getId();
            $markup->addRow($mine === null
                ? InlineKeyboardButton::make('За блокування', callback_data: 'bvote:' . $id . ':yes')
                : InlineKeyboardButton::make(
                    $mine ? '✅ Ви проголосували: За' : '✅ Ви проголосували: Проти',
                    callback_data: self::NOOP_CALLBACK,
                ),
                ...($mine === null
                    ? [InlineKeyboardButton::make('Проти', callback_data: 'bvote:' . $id . ':no')]
                    : []),
            );
        }

        // Directly under the tallies it re-reads, above the rows that navigate away.
        $this->withRefresh($markup);
        $this->withAsk($markup, $account);
        $this->withArchive($markup)->addRow(StartCommand::homeButton());

        $this->respond($bot, $edit, implode("\n", $lines), $markup, $refreshing);
    }

    /**
     * «➕ Запропонувати питання», or the way to withdraw the one you already asked.
     *
     * One open question per **account**: a household asking three things at once is the
     * failure mode this stops, and a family sharing one рахунок shares one turn. When they
     * have one, the button becomes the way to take it down — an author must always be able
     * to withdraw their own question, or the only route out is asking an admin.
     */
    private function withAsk(InlineKeyboardMarkup $markup, ?Account $account): InlineKeyboardMarkup
    {
        $mine = $this->voteService->openQuestionOf($account);

        if ($mine !== null) {
            return $markup->addRow(InlineKeyboardButton::make(
                '🗑 Зняти моє питання',
                callback_data: 'vote:drop:' . $mine->getId(),
            ));
        }

        return $markup->addRow(InlineKeyboardButton::make(
            '➕ Запропонувати питання',
            callback_data: VoteAsk::START_CALLBACK,
        ));
    }

    /**
     * The author takes their own question down.
     *
     * Only theirs, and only while it is open — checked here rather than trusted from the
     * callback, because a button id is not an authorisation.
     */
    private function dropOwn(Nutgram $bot, int $campaignId): void
    {
        $account = $this->currentAccount($bot);
        $mine = $this->voteService->openQuestionOf($account);

        if ($mine === null || $mine->getId() !== $campaignId) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Це питання вже неактуальне.');

            return;
        }

        $this->voteService->cancelCampaign($mine);

        $this->renderMenu($bot, edit: true, notice: '🗑 Ваше питання знято.');
    }

    /** «🔄 Оновити» — re-render this same message with the counts as they are now. */
    private function withRefresh(InlineKeyboardMarkup $markup): InlineKeyboardMarkup
    {
        return $markup->addRow(InlineKeyboardButton::make(
            '🔄 Оновити',
            callback_data: self::REFRESH_CALLBACK,
        ));
    }

    /**
     * The archive row, offered only when there is something in it.
     *
     * A vote that is over is the only evidence the house has that voting does anything —
     * «за це вже голосували, ось як» is what stops the same question being asked in the
     * chat every spring. An empty «Минулі голосування» would just be a dead button.
     */
    private function withArchive(InlineKeyboardMarkup $markup): InlineKeyboardMarkup
    {
        if ($this->campaignRepository->findFinished(1) === []) {
            return $markup;
        }

        return $markup->addRow(InlineKeyboardButton::make(
            '📜 Минулі голосування',
            callback_data: self::PAST_CALLBACK,
        ));
    }

    /**
     * What the house has decided, newest first.
     *
     * Both kinds in one list: they are the same act — the house was asked something and
     * answered — and splitting them would hide how rarely either happens.
     */
    private function renderArchive(Nutgram $bot): void
    {
        $finished = $this->campaignRepository->findFinished(20);

        $lines = ['📜 <b>Минулі голосування</b>', ''];

        if ($finished === []) {
            $lines[] = 'Поки що жодного завершеного голосування.';
        }

        foreach ($finished as $campaign) {
            $yes = (int)$campaign->getResultYes();
            $no = (int)$campaign->getResultNo();
            $closed = $campaign->getClosedAt()?->format('d.m.Y') ?? '—';

            if ($campaign->isQuestion()) {
                $lines[] = sprintf(
                    "❓ <b>%s</b>\nЗа: <b>%d</b> · Проти: <b>%d</b> · %s",
                    self::esc((string)$campaign->getQuestion()),
                    $yes,
                    $no,
                    $closed,
                );
            } else {
                // The outcome in a word, then the numbers behind it. «Заблоковано» without
                // the split reads as an accusation the house cannot check.
                $lines[] = sprintf(
                    "%s <b>%s</b>\nЗа: <b>%d</b> · Проти: <b>%d</b> · %s",
                    $campaign->getStatus() === \App\Entity\BlockVoteCampaign::STATUS_PASSED ? '🚫' : '✅',
                    self::esc($this->voteService->candidateLabel($campaign->getCandidate())),
                    $yes,
                    $no,
                    $closed,
                );
                $lines[] = $campaign->getStatus() === \App\Entity\BlockVoteCampaign::STATUS_PASSED
                    ? '<i>Рішення: заблокувати на 30 днів.</i>'
                    : '<i>Рішення: не блокувати — голосів не вистачило.</i>';
            }

            $lines[] = '';
        }

        $this->respond(
            $bot,
            edit: true,
            text: implode("\n", $lines),
            markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🗳️ Відкриті голосування', callback_data: self::MENU_CALLBACK))
                ->addRow(StartCommand::homeButton()),
        );
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function castVote(Nutgram $bot, string $data): void
    {
        // bvote:<id>:yes|no
        $parts = explode(':', $data);
        $campaignId = (int)($parts[1] ?? 0);
        $value = ($parts[2] ?? '') === 'yes';

        $account = $this->currentAccount($bot);
        $campaign = $campaignId > 0 ? $this->campaignRepository->find($campaignId) : null;

        if (!$account || !$campaign || !$this->voteService->isVotable($campaign) || !$this->voteService->isEligibleVoter($account, $campaign)) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Це голосування більше недоступне.');
            return;
        }

        $result = $this->voteService->recordVote(
            $campaign,
            $account,
            $value,
            $this->telegramUserService->getCurrentUser(),
        );

        if (($result['already'] ?? false) === true) {
            // Says what their vote is rather than only refusing: somebody tapping again is
            // usually checking, not attacking the rule.
            $this->renderMenu($bot, edit: true, notice: sprintf(
                'ℹ️ Ви вже проголосували: <b>%s</b>. Голос змінити не можна.',
                $result['value'] ? 'За' : 'Проти',
            ));

            return;
        }

        if ($result['passed']) {
            $notice = sprintf(
                '✅ Ваш голос враховано. Рішення ухвалено: <b>%s</b> заблоковано.',
                $this->voteService->candidateLabel($campaign->getCandidate())
            );
        } else {
            $notice = '✅ Ваш голос враховано.';
        }

        $this->renderMenu($bot, edit: true, notice: $notice);
    }

    private function respond(
        Nutgram $bot,
        bool $edit,
        string $text,
        InlineKeyboardMarkup $markup,
        bool $refreshing = false,
    ): void {
        if ($edit) {
            try {
                $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);

                if ($refreshing) {
                    $this->toast($bot, '🔄 Оновлено');
                }

                return;
            } catch (\Throwable $e) {
                // Telegram refuses an edit that would change nothing, and on a refresh
                // button that is the *common* case — nobody has voted since the last look.
                // Falling through to sendMessage() there would post a second copy of the
                // menu on every tap, so the tap answers with a toast instead. Any other
                // failure still falls through: the resident asked to see the menu.
                if ($refreshing && str_contains($e->getMessage(), 'not modified')) {
                    $this->toast($bot, 'Без змін — нових голосів немає');

                    return;
                }
            }
        }
        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }

    /** Never fatal: a button that redrew the message correctly has done its job. */
    private function toast(Nutgram $bot, string $text): void
    {
        try {
            $bot->answerCallbackQuery(text: $text);
        } catch (\Throwable) {
            // ignored
        }
    }
}
