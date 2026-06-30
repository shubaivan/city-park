<?php

namespace App\Telegram\Voting\Command;

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

    public function __construct(
        private TelegramUserService $telegramUserService,
        private BlockVoteService $voteService,
        private BlockVoteCampaignRepository $campaignRepository,
        private BlockVoteBallotRepository $ballotRepository,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->isCallbackQuery() ? ($bot->callbackQuery()->data ?? '') : '';

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

    private function renderMenu(Nutgram $bot, bool $edit, ?string $notice = null): void
    {
        $account = $this->currentAccount($bot);

        if (!$account) {
            $this->respond(
                $bot,
                $edit,
                "🗳️ <b>Голосування</b>\n\nВаш аккаунт не підтверджений ОСББ — голосування недоступне.\n"
                . "Зв'яжіться з Аліною Бухгалтером (+380 93 658 32 02).",
                InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton())
            );
            return;
        }

        if (!$account->isActive() || !$account->isApartment()) {
            $this->respond(
                $bot,
                $edit,
                "🗳️ <b>Голосування</b>\n\nГолосувати можуть лише власники квартир з активним аккаунтом.",
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
                InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton())
            );
            return;
        }

        $lines = [];
        if ($notice) {
            $lines[] = $notice;
            $lines[] = '';
        }
        $lines[] = '🗳️ <b>Голосування за блокування</b>';
        $lines[] = '';
        $lines[] = 'Один аккаунт — один голос. Свій вибір можна змінити до завершення голосування.';
        $lines[] = '';

        $markup = InlineKeyboardMarkup::make();

        foreach ($campaigns as $campaign) {
            $tally = $this->ballotRepository->tally($campaign);
            $ballot = $this->ballotRepository->findOneByCampaignAndVoter($campaign, $account);
            $mine = $ballot === null ? null : $ballot->getValue();

            $priorBlocks = $campaign->getCandidate()->getVoteBlockCount();
            $lines[] = sprintf(
                "👤 <b>%s</b>%s\nЗа: <b>%d</b> · Проти: <b>%d</b> · Треба «За»: <b>%d</b> з %d\nДо: <b>%s</b>%s",
                $this->voteService->candidateLabel($campaign->getCandidate()),
                $priorBlocks > 0 ? sprintf("\n<i>раніше блокувався за рішенням спільноти: %d раз(и)</i>", $priorBlocks) : '',
                $tally['yes'],
                $tally['no'],
                $campaign->yesNeeded(),
                $campaign->getEligibleCount(),
                $campaign->getDeadlineAt()->format('d.m.Y'),
                $mine === null ? '' : ($mine ? "\n<i>Ваш голос: За</i>" : "\n<i>Ваш голос: Проти</i>")
            );
            $lines[] = '';

            $id = $campaign->getId();
            $markup->addRow(
                InlineKeyboardButton::make(
                    ($mine === true ? '✅ ' : '') . 'За блокування',
                    callback_data: 'bvote:' . $id . ':yes'
                ),
                InlineKeyboardButton::make(
                    ($mine === false ? '✅ ' : '') . 'Проти',
                    callback_data: 'bvote:' . $id . ':no'
                ),
            );
        }

        $markup->addRow(StartCommand::homeButton());

        $this->respond($bot, $edit, implode("\n", $lines), $markup);
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

        $result = $this->voteService->recordVote($campaign, $account, $value);

        if ($result['passed']) {
            $notice = sprintf(
                '✅ Ваш голос враховано. Рішення ухвалено: <b>%s</b> заблоковано.',
                $this->voteService->candidateLabel($campaign->getCandidate())
            );
        } elseif ($result['changed']) {
            $notice = '🔁 Ваш голос змінено.';
        } else {
            $notice = '✅ Ваш голос враховано.';
        }

        $this->renderMenu($bot, edit: true, notice: $notice);
    }

    private function respond(Nutgram $bot, bool $edit, string $text, InlineKeyboardMarkup $markup): void
    {
        if ($edit) {
            try {
                $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
                return;
            } catch (\Throwable) {
                // fall through to a fresh message
            }
        }
        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }
}
