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

    /** One vote, opened from the list: everything about it and its two buttons. */
    public const CARD_PREFIX = 'vote:view:';

    /** «🔄 Оновити» on a card — the tally is a snapshot and a vote runs a week. */
    public const REFRESH_PREFIX = 'vote:refresh:';

    /** The index, page by page. */
    public const PAGE_PREFIX = 'vote:page:';

    /**
     * Open votes per page of the index.
     *
     * One button per row, so this is a screenful and no more. The house has had three
     * campaigns in three months, but «а якщо їх буде сорок» is the question every board
     * here has already answered the same way, and answering it once costs less than the
     * screen that would otherwise have to be scrolled past to reach «На головну».
     */
    private const PAGE_SIZE = 8;

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

        if (str_starts_with($data, self::CARD_PREFIX)) {
            $this->openCard($bot, (int)substr($data, strlen(self::CARD_PREFIX)));
            return;
        }

        if (str_starts_with($data, self::REFRESH_PREFIX)) {
            $this->openCard($bot, (int)substr($data, strlen(self::REFRESH_PREFIX)), refreshing: true);
            return;
        }

        if (str_starts_with($data, self::PAGE_PREFIX)) {
            $this->renderMenu($bot, edit: true, page: (int)substr($data, strlen(self::PAGE_PREFIX)));
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
     * Straight onto the vote the post was about, now that a vote has a card of its own —
     * the link named one thing and used to answer with the whole section, which on a busy
     * week is the reader hunting for the row they came from. When it is over or not theirs
     * to vote on, the section opens instead: the archive row is one tap from there, which
     * is an answer rather than an error.
     */
    public function openFromDeepLink(Nutgram $bot, int $campaignId): void
    {
        $account = $this->currentAccount($bot);
        $campaign = $campaignId > 0 ? $this->campaignRepository->find($campaignId) : null;

        if (!$account || !$campaign || !$this->voteService->isVotable($campaign)
            || !$this->voteService->isEligibleVoter($account, $campaign)) {
            $this->renderMenu($bot, edit: false);

            return;
        }

        $this->renderCard($bot, $campaign, $account, false, count($this->votableFor($account)) === 1);
    }

    /**
     * The section: an index of open votes, or the single vote itself when there is one.
     *
     * It used to render every open vote in full into one message and stack all their
     * buttons at the bottom. With two questions open (09.09.2026) nobody could tell which
     * row answered which — the keyboard hangs screens below the text it belongs to — and
     * the answer to «а якщо їх буде сорок» is a message no phone can read. Same shape as
     * every other board here: buttons in the index, one card behind each.
     */
    private function renderMenu(
        Nutgram $bot,
        bool $edit,
        ?string $notice = null,
        bool $refreshing = false,
        int $page = 1,
    ): void {
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

        $campaigns = $this->votableFor($account);

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

        // One vote is not a list: a menu of a single button, to reach the only thing the
        // menu is about, is a tap that answers nothing. Same call «📌 Моє оголошення»
        // makes on the services board.
        if (count($campaigns) === 1) {
            $this->renderCard($bot, $campaigns[0], $account, $edit, true, $notice, $refreshing);

            return;
        }

        $this->renderList($bot, $campaigns, $account, $edit, $notice, $refreshing, $page);
    }

    /** Open votes this account may actually cast a ballot on, newest deadline last. */
    private function votableFor(Account $account): array
    {
        return array_values(array_filter(
            $this->campaignRepository->findOpen(),
            fn(BlockVoteCampaign $c) => $this->voteService->isVotable($c)
                && $this->voteService->isEligibleVoter($account, $c)
        ));
    }

    /**
     * The index: what is open, how many, and one button each.
     *
     * The page number is clamped rather than trusted — a callback from an older, longer
     * list must not answer with an empty page, the same rule the debtors' board is written
     * around.
     */
    private function renderList(
        Nutgram $bot,
        array $campaigns,
        Account $account,
        bool $edit,
        ?string $notice,
        bool $refreshing,
        int $page,
    ): void {
        $total = count($campaigns);
        $pages = max(1, (int)ceil($total / self::PAGE_SIZE));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * self::PAGE_SIZE;
        $shown = array_slice($campaigns, $offset, self::PAGE_SIZE);

        $lines = [];

        if ($notice) {
            $lines[] = $notice;
            $lines[] = '';
        }

        $lines[] = '🗳️ <b>Голосування</b>';
        $lines[] = '';
        $lines[] = sprintf('Відкритих голосувань: <b>%d</b>. Оберіть, щоб прочитати й проголосувати.', $total);
        $lines[] = '<i>Один акаунт — один голос. Голос остаточний: змінити його не можна.</i>';

        if ($pages > 1) {
            $lines[] = '';
            $lines[] = sprintf(
                '<i>Показано %d–%d · сторінка %d з %d.</i>',
                $offset + 1,
                $offset + count($shown),
                $page,
                $pages,
            );
        }

        $markup = InlineKeyboardMarkup::make();

        foreach ($shown as $campaign) {
            $markup->addRow(InlineKeyboardButton::make(
                $this->buttonLabel($campaign, $account),
                callback_data: self::CARD_PREFIX . $campaign->getId(),
            ));
        }

        if ($pages > 1) {
            $nav = [];

            if ($page > 1) {
                $nav[] = InlineKeyboardButton::make('⬅️', callback_data: self::PAGE_PREFIX . ($page - 1));
            }

            $nav[] = InlineKeyboardButton::make(
                sprintf('%d/%d', $page, $pages),
                callback_data: self::NOOP_CALLBACK,
            );

            if ($page < $pages) {
                $nav[] = InlineKeyboardButton::make('➡️', callback_data: self::PAGE_PREFIX . ($page + 1));
            }

            $markup->addRow(...$nav);
        }

        $this->withAsk($markup, $account);
        $this->withArchive($markup)->addRow(StartCommand::homeButton());

        $this->respond($bot, $edit, implode("\n", $lines), $markup, $refreshing);
    }

    /**
     * «до 16.09 · ❓ Чи погоджуєтеся ви… ✅»
     *
     * The deadline leads, at a fixed width, so the dates line up down the column and the
     * list is read in one movement. On this board it is the closing date rather than the
     * opening one: a vote is something you still have time to do, or you have not.
     *
     * The ✅ is last, because Telegram truncates a button from the right and «ви вже
     * проголосували» is the only part that can be lost without costing anybody a ballot.
     */
    private function buttonLabel(BlockVoteCampaign $campaign, Account $account): string
    {
        $voted = $this->ballotRepository->findOneByCampaignAndVoter($campaign, $account) !== null;

        $title = $campaign->isQuestion()
            ? '❓ ' . (string)$campaign->getQuestion()
            : '👤 ' . $this->voteService->candidateLabel($campaign->getCandidate());

        return sprintf(
            'до %s · %s%s',
            $campaign->getDeadlineAt()->format('d.m'),
            self::shorten($title, 42),
            $voted ? ' ✅' : '',
        );
    }

    private static function shorten(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max - 1) . '…';
    }

    /** A card opened from the index, or refreshed in place. */
    private function openCard(Nutgram $bot, int $campaignId, bool $refreshing = false): void
    {
        $account = $this->currentAccount($bot);
        $campaign = $campaignId > 0 ? $this->campaignRepository->find($campaignId) : null;

        if (!$account || !$campaign || !$this->voteService->isVotable($campaign)
            || !$this->voteService->isEligibleVoter($account, $campaign)) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Це голосування більше недоступне.');

            return;
        }

        $this->renderCard(
            $bot,
            $campaign,
            $account,
            true,
            count($this->votableFor($account)) === 1,
            null,
            $refreshing,
        );
    }

    /**
     * One vote, alone in its own message: the question, the count, the deadline and the
     * two buttons directly under it.
     *
     * `$standalone` is the only-open-vote case, where there is no list to go back to — the
     * card carries the section's own rows instead of «⬅️ До списку».
     */
    private function renderCard(
        Nutgram $bot,
        BlockVoteCampaign $campaign,
        Account $account,
        bool $edit,
        bool $standalone,
        ?string $notice = null,
        bool $refreshing = false,
    ): void {
        $tally = $this->ballotRepository->tally($campaign);
        $ballot = $this->ballotRepository->findOneByCampaignAndVoter($campaign, $account);
        $mine = $ballot === null ? null : $ballot->getValue();
        $voted = $mine === null ? '' : ($mine ? "\n<i>Ваш голос: За</i>" : "\n<i>Ваш голос: Проти</i>");

        $lines = [];

        if ($notice) {
            $lines[] = $notice;
            $lines[] = '';
        }

        if ($campaign->isQuestion()) {
            // No «треба N»: a question crosses no line and enacts nothing, so a target
            // beside it would be a promise the bot cannot keep. What it does say is who
            // decides — otherwise a resident reasonably reads a vote as binding.
            $details = $campaign->getDetails();

            $lines[] = sprintf(
                "❓ <b>%s</b>%s\nЗа: <b>%d</b> · Проти: <b>%d</b>\n🗓 %s — <b>%s</b>%s",
                self::esc((string)$campaign->getQuestion()),
                $details !== null ? "\n<i>" . self::esc($details) . '</i>' : '',
                $tally['yes'],
                $tally['no'],
                // Started and ends: «до 15.09» alone leaves a reader unable to tell a vote
                // opened this morning from one that has been sitting a week with three
                // ballots on it, and those call for different urgency.
                $campaign->getCreatedAt()?->format('d.m') ?? '—',
                $campaign->getDeadlineAt()->format('d.m.Y'),
                $voted,
            );
            $lines[] = '<i>Рішення ухвалює ОСББ — результат голосування покаже, чого хочуть мешканці.</i>';
        } else {
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
        }

        $lines[] = '';
        $lines[] = '<i>Один акаунт — один голос. Голос остаточний: змінити його не можна.</i>';

        $id = $campaign->getId();
        $yes = $campaign->isQuestion() ? '👍 За' : 'За блокування';
        $no = $campaign->isQuestion() ? '👎 Проти' : 'Проти';

        $markup = InlineKeyboardMarkup::make();

        // Once cast, the row becomes a statement rather than a choice: a live button under
        // a final vote invites a tap that can only be refused.
        $markup->addRow($mine === null
            ? InlineKeyboardButton::make($yes, callback_data: 'bvote:' . $id . ':yes')
            : InlineKeyboardButton::make(
                $mine ? '✅ Ви проголосували: За' : '✅ Ви проголосували: Проти',
                callback_data: self::NOOP_CALLBACK,
            ),
            ...($mine === null
                ? [InlineKeyboardButton::make($no, callback_data: 'bvote:' . $id . ':no')]
                : []),
        );

        $markup->addRow(InlineKeyboardButton::make(
            '🔄 Оновити',
            callback_data: self::REFRESH_PREFIX . $id,
        ));

        if ($standalone) {
            $this->withAsk($markup, $account);
            $this->withArchive($markup);
        } else {
            $markup->addRow(InlineKeyboardButton::make(
                '⬅️ До списку',
                callback_data: self::MENU_CALLBACK,
            ));
        }

        $markup->addRow(StartCommand::homeButton());

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

        // The card this tap came from, redrawn in place — not the whole section: the
        // reader is looking at one vote and must go on looking at it.
        $standalone = count($this->votableFor($account)) === 1;

        if (($result['already'] ?? false) === true) {
            // Says what their vote is rather than only refusing: somebody tapping again is
            // usually checking, not attacking the rule.
            $this->renderCard($bot, $campaign, $account, true, $standalone, sprintf(
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

        $this->renderCard($bot, $campaign, $account, true, $standalone, $notice);
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
