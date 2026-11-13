<?php

namespace App\Telegram\Guard\Command;

use App\Entity\GateEvent;
use App\Entity\QrScan;
use App\Service\GateLogService;
use App\Service\GatePress;
use App\Service\SchedulePavilionService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «🚗 В'їзд» / «🚙 Виїзд» under the guard's valid scan (Иван, 13.11.2026).
 *
 * The rules live in GateLogService; this draws them:
 *
 * - **recorded** → the two buttons become one dead «✅ Обрано: 🚗 В'їзд · 14:32», so the
 *   message itself says what was chosen and cannot be pressed again, and a separate
 *   «Зафіксовано: …» line is sent — that one is the receipt the guard scrolls back to;
 * - **already** → the same dead button, and a pop-up naming what was recorded;
 * - **expired** → the buttons go, and a pop-up says to scan again;
 * - **forbidden / invalid** → a pop-up only. The message belongs to the guard; somebody
 *   else's thumb must not be able to change it.
 */
class GateEventCommand
{
    public function __construct(
        private GateLogService $gate,
        private TelegramUserService $telegramUserService,
        private ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $data = (string)($bot->callbackQuery()?->data ?? '');

        if ($data === GateLogService::NOOP_CALLBACK) {
            $bot->answerCallbackQuery();

            return;
        }

        $user = $this->telegramUserService->getCurrentUser();
        $press = $this->gate->press($data, $user, SchedulePavilionService::createNewDate());

        $this->logger?->info('gate press', [
            'telegram_id' => $user?->getTelegramId(),
            'data' => $data,
            'status' => $press->status,
        ]);

        switch ($press->status) {
            case GatePress::RECORDED:
                $bot->answerCallbackQuery(text: 'Зафіксовано: ' . $press->event->getDirectionLabel());
                $this->settle($bot, $press->scan, $press->event);
                $bot->sendMessage(
                    text: GateLogService::confirmation($press->event),
                    parse_mode: ParseMode::HTML,
                );
                break;

            case GatePress::ALREADY:
                $bot->answerCallbackQuery(
                    text: $press->event !== null
                        ? sprintf('Вже зафіксовано: %s о %s', $press->event->getDirectionLabel(), $press->event->getCreatedAt()->format('H:i'))
                        : 'Вже зафіксовано.',
                    show_alert: true,
                );
                $this->settle($bot, $press->scan, $press->event);
                break;

            case GatePress::EXPIRED:
                $bot->answerCallbackQuery(
                    text: sprintf('Минуло понад %d хв після сканування. Відскануйте код ще раз.', GateLogService::TTL_MINUTES),
                    show_alert: true,
                );
                $this->settle($bot, $press->scan, null);
                break;

            case GatePress::FORBIDDEN:
                $bot->answerCallbackQuery(text: 'Ця кнопка — лише для охоронця, який сканував код.', show_alert: true);
                break;

            default:
                $bot->answerCallbackQuery(text: 'Не вдалося зафіксувати. Відскануйте код ще раз.', show_alert: true);
        }
    }

    /**
     * Replace the two buttons: with the choice, when there is one, or with nothing. The
     * scan's own way onward (the pavilion board, or home under a pass) stays.
     */
    private function settle(Nutgram $bot, ?QrScan $scan, ?GateEvent $event): void
    {
        $markup = InlineKeyboardMarkup::make();

        if ($event !== null) {
            $markup->addRow(InlineKeyboardButton::make(
                sprintf('✅ Обрано: %s · %s', $event->getDirectionLabel(), $event->getCreatedAt()->format('H:i')),
                callback_data: GateLogService::NOOP_CALLBACK,
            ));
        }

        $markup->addRow($scan?->getKind() === QrScan::KIND_GUEST
            ? StartCommand::homeButton()
            : GuardScanCommand::boardButton());

        try {
            $bot->editMessageReplyMarkup(reply_markup: $markup);
        } catch (\Throwable $e) {
            // «message is not modified» on a second tap, or a message too old to edit:
            // the event is written either way, and the receipt says so.
            $this->logger?->info('gate markup not edited', ['error' => $e->getMessage()]);
        }
    }
}
