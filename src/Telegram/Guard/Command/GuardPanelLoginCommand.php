<?php

namespace App\Telegram\Guard\Command;

use App\Service\PanelLoginLink;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «🔐 Вхід у панель» — the gate's way into the QR scan log, with no password (Иван,
 * 12.11.2026). Only for GUARD_TELEGRAM_IDS; anybody else is told it is not for them.
 *
 * The link rides on a **URL button, never in the text**: Telegram fetches a preview of a
 * link in a message, and that fetch would burn the one-time token before the guard taps.
 * Also `/panel`, not on the pushed slash menu — like `/guard`, it belongs to one phone.
 */
class GuardPanelLoginCommand
{
    public const MENU_CALLBACK = 'guard-panel-login';

    public function __construct(
        private PanelLoginLink $links,
        private TelegramUserService $users,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->users->getCurrentUser();

        try {
            $url = $this->links->issue($user);
        } catch (\Throwable $e) {
            $this->logger->error('panel login link failed', ['error' => $e->getMessage()]);
            $bot->isCallbackQuery() && $bot->answerCallbackQuery();
            $bot->sendMessage(text: '⚠️ Не вдалося створити посилання. Спробуйте ще раз за хвилину.');

            return;
        }

        if ($url === null) {
            $text = 'Цей вхід — лише для охорони ЖК.';
            $bot->isCallbackQuery()
                ? $bot->answerCallbackQuery(text: $text, show_alert: true)
                : $bot->sendMessage(text: $text);

            return;
        }

        $bot->isCallbackQuery() && $bot->answerCallbackQuery();
        $this->logger->info('panel login link issued', ['telegram_id' => $user?->getTelegramId()]);

        $bot->sendMessage(
            text: sprintf(
                "🔐 Вхід у панель охорони — журнал сканувань QR.\n\n"
                . "Натисніть кнопку нижче. Посилання одноразове і діє %d хвилин; "
                . "якщо не встигли — натисніть «🔐 Вхід у панель» ще раз.\n"
                . "Нікому його не пересилайте.",
                PanelLoginLink::TTL_MINUTES,
            ),
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🔐 Відкрити панель', url: $url))
                ->addRow(StartCommand::homeButton()),
        );
    }
}
