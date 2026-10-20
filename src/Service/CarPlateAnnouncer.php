<?php

namespace App\Service;

use App\Entity\Account;
use App\Repository\CarPlateRepository;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * «🚗 буд. 19, кв. 85 внесли авто» in the residents' chat, in its own topic.
 *
 * The list is voluntary and fills only if people see their neighbours filling it, so each
 * household that joins is said out loud — with the running count, which is the part that
 * makes the next one reach for the button. **The number itself is never posted**: the list
 * is read by the household, the guard and the ОСББ, and a post in a chat of 457 would be
 * exactly the «чия це машина» lookup `CarPlateService` refuses to be.
 *
 * Once per household, on its first number — a flat adding its second car is not news, and
 * three posts for one family is noise in a topic whose whole message is «ще одна квартира».
 * Silent when `RESIDENT_CHAT_TOPIC_CARS` is unset (no General fallback, the services
 * board's call), and never fatal: the number is saved either way.
 */
class CarPlateAnnouncer
{
    public function __construct(
        private Nutgram $bot,
        private ResidentChatService $residentChat,
        private DeepLink $links,
        private CarPlateRepository $plates,
        private LoggerInterface $logger,
    ) {}

    /** Call after a number was added; posts only when it was the household's first. */
    public function afterAdded(Account $account): void
    {
        $topic = $this->residentChat->topic(ResidentChatService::TOPIC_CARS);

        if (!$this->residentChat->isConfigured() || $topic === null) {
            return;
        }

        try {
            if (count($this->plates->forAccount($account)) !== 1) {
                return;
            }

            $this->bot->sendMessage(
                text: self::post($account->getPlaceLabel(), $this->plates->householdCount()),
                chat_id: (int)$this->residentChat->chatId(),
                message_thread_id: $topic,
                parse_mode: ParseMode::HTML,
                disable_notification: true,
                reply_markup: $this->links->button(DeepLink::KIND_CARS, $account->getId(), '🚗 Внести своє авто'),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('car plate chat post failed', [
                'account_id' => $account->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** The flat and the count, and no plate — see the class comment. */
    public static function post(string $place, int $households): string
    {
        return sprintf(
            "🚗 <b>%s</b> — внесли своє авто до переліку авто мешканців.\n"
                . "Уже внесли: <b>%d %s</b>.\n\n"
                . '<i>За бажанням, не обов\'язково. Самі номери в чат не потрапляють — їх бачать лише охорона та ОСББ.</i>',
            htmlspecialchars(mb_strtoupper(mb_substr($place, 0, 1)) . mb_substr($place, 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $households,
            self::flats($households),
        );
    }

    private static function flats(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        return match (true) {
            $mod10 === 1 && $mod100 !== 11 => 'квартира',
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => 'квартири',
            default => 'квартир',
        };
    }
}
