<?php

namespace App\Telegram\SchedulePavilion\Command;

use App\Entity\ScheduledSet;
use App\Service\SchedulePavilionService;
use App\Service\UkDateFormatter;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

class BookingHistory
{
    private const DAYS = 30;
    private const MESSAGE_CHAR_LIMIT = 3800;

    public function __construct(
        private SchedulePavilionService $schedulePavilionService,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $history = $this->schedulePavilionService->getHistory(self::DAYS);

        if (!$history) {
            $bot->sendMessage(
                text: '📜 <b>Історія бронювань за останні ' . self::DAYS . ' днів порожня</b>',
                parse_mode: ParseMode::HTML,
            );
            return;
        }

        foreach ($this->renderMessages($history) as $msg) {
            $bot->sendMessage(text: $msg, parse_mode: ParseMode::HTML);
        }
    }

    /**
     * @param array<string, ScheduledSet[]> $history
     * @return string[]
     */
    private function renderMessages(array $history): array
    {
        $header = '📜 <b>Історія бронювань за ' . self::DAYS . " днів:</b>\n";
        $messages = [];
        $current = $header;

        foreach ($history as $sets) {
            $first = $sets[0]->getScheduledDateTime();
            $section = "\n📅 <b>" . UkDateFormatter::dayDate($first) . "</b>\n";
            foreach ($sets as $set) {
                $section .= $this->formatEntry($set);
            }

            if (strlen($current) + strlen($section) > self::MESSAGE_CHAR_LIMIT && $current !== '') {
                $messages[] = $current;
                $current = '';
            }
            $current .= $section;
        }

        if ($current !== '') {
            $messages[] = $current;
        }

        return $messages;
    }

    private function formatEntry(ScheduledSet $set): string
    {
        $dt = $set->getScheduledDateTime();
        $tu = $set->getTelegramUserId();
        $account = $tu->getAccount();

        $name = trim(($tu->getFirstName() ?? '') . ' ' . ($tu->getLastName() ?? ''));
        if ($name === '') {
            $name = $tu->getUsername() ? '@' . $tu->getUsername() : '—';
        } elseif ($tu->getUsername()) {
            $name .= ' (@' . $tu->getUsername() . ')';
        }

        $phone = $tu->getPhoneNumber() ?: '—';

        if ($account) {
            $address = trim(sprintf(
                '%s %s, кв. %s',
                $account->getStreet() ?? '',
                $account->getHouseNumber() ?? '',
                $account->getApartmentNumber() ?? '',
            ));
        } else {
            $address = '—';
        }

        return sprintf(
            "   🕒 %s · Альт. %d · %s · 📞 %s · 🏠 %s\n",
            $dt->format('H:i'),
            $set->getPavilion(),
            $this->escape($name),
            $this->escape($phone),
            $this->escape($address),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
