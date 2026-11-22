<?php

namespace App\Service\ActionLog;

use App\Entity\AdminActionLog;
use App\EventSubscriber\AdminActionSubscriber;
use App\Repository\TelegramUserRepository;

/**
 * Turns journal rows into what the page and the cards draw.
 *
 * One button usually leaves two kinds of row: the request («Роль мешканця», POST, 302) and
 * the field change it caused («Тестова Ольга (#12): роль: не вказано → Орендар»). Shown
 * side by side they say the same thing twice, so a request that has changes behind it is
 * folded into them as a small «через …» note; a request with none (a chat kick, a refused
 * attempt) is shown on its own. The bot's rows get the person's name instead of a
 * Telegram id.
 */
class ActionLogView
{
    public function __construct(private TelegramUserRepository $users) {}

    /**
     * @param list<AdminActionLog> $rows
     * @return list<array{entry: AdminActionLog, via: ?string, who: string, details: list<array{field: string, old: string, new: string}>}>
     */
    public function present(array $rows, ?int $focusAccount = null): array
    {
        $changed = [];
        $requests = [];

        foreach ($rows as $row) {
            if ($row->getRequestId() === null) {
                continue;
            }

            if ($row->getKind() === AdminActionLog::KIND_REQUEST) {
                // A failed request is never folded away: its status and form are the news.
                if ((int)$row->getStatus() >= 400) {
                    continue;
                }
                $requests[$row->getRequestId()] = $row;
            } else {
                $changed[$row->getRequestId()] = true;
            }
        }

        $botNames = $this->botNames($rows);
        $out = [];

        foreach ($rows as $row) {
            $rid = $row->getRequestId();

            if ($row->getKind() === AdminActionLog::KIND_REQUEST && $rid !== null && isset($changed[$rid]) && isset($requests[$rid])) {
                continue;
            }

            $via = null;

            if ($row->getKind() !== AdminActionLog::KIND_REQUEST && $rid !== null && isset($requests[$rid])) {
                $route = (string)$requests[$rid]->getRoute();
                $via = AdminActionSubscriber::ROUTE_LABELS[$route] ?? $route;
            }

            $out[] = [
                'entry' => $row,
                'via' => $via,
                'who' => $this->who($row, $botNames),
                'details' => self::details($row, $focusAccount),
                'summary' => self::summary($row, $focusAccount),
            ];
        }

        return $out;
    }

    /**
     * @param list<AdminActionLog> $rows
     * @return array<string, string> telegram id => «Ім'я (#id)»
     */
    private function botNames(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (($tg = $row->getBotTelegramId()) !== null) {
                $ids[$tg] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $names = [];

        try {
            foreach ($this->users->findBy(['telegram_id' => array_map('strval', array_keys($ids))]) as $user) {
                $names[(string)$user->getTelegramId()] = sprintf('%s (#%d)', $user->getDisplayName(), $user->getId());
            }
        } catch (\Throwable) {
            // A name is a nicety; the page still reads with the bare id.
        }

        return $names;
    }

    /** @param array<string, string> $botNames */
    private function who(AdminActionLog $row, array $botNames): string
    {
        $tg = $row->getBotTelegramId();

        if ($tg === null) {
            return $row->getActorLabel();
        }

        return '🤖 бот · ' . ($botNames[$tg] ?? 'tg ' . $tg);
    }

    /**
     * Field by field, for the «деталі» fold. A request row lists its form fields instead.
     *
     * @return list<array{field: string, old: string, new: string}>
     */
    public static function details(AdminActionLog $row, ?int $focusAccount = null): array
    {
        $changes = $row->getChanges() ?? [];
        $out = [];

        foreach ($changes as $field => $value) {
            if ($row->getKind() !== AdminActionLog::KIND_REQUEST && is_array($value) && array_is_list($value) && count($value) === 2) {
                $out[] = ['field' => (string)$field, 'old' => self::text($value[0]), 'new' => self::text($value[1])];
            } elseif ($row->getKind() !== AdminActionLog::KIND_REQUEST && is_array($value)) {
                // A bulk row: id => [old, new]. On one object's history: just that object;
                // otherwise a count plus the first few.
                if ($focusAccount !== null && isset($value[$focusAccount])) {
                    $out[] = ['field' => (string)$field, 'old' => self::text($value[$focusAccount][0] ?? null), 'new' => self::text($value[$focusAccount][1] ?? null)];
                    continue;
                }

                $shown = array_slice($value, 0, 30, true);
                $lines = [];

                foreach ($shown as $id => $pair) {
                    $lines[] = sprintf('#%s: %s → %s', $id, self::text($pair[0] ?? null), self::text($pair[1] ?? null));
                }

                $more = count($value) - count($shown);
                $out[] = ['field' => (string)$field, 'old' => '', 'new' => implode('; ', $lines) . ($more > 0 ? sprintf(' … і ще %d', $more) : '')];
            } else {
                $out[] = ['field' => (string)$field, 'old' => '', 'new' => self::text($value)];
            }
        }

        return $out;
    }

    /** A bulk row read on one object's history says what happened to that object. */
    private static function summary(AdminActionLog $row, ?int $focusAccount): string
    {
        if ($focusAccount === null || $row->getAccountId() !== null || !str_starts_with($row->getSummary(), 'Масова зміна:')) {
            return $row->getSummary();
        }

        foreach ($row->getChanges() ?? [] as $field => $map) {
            if (is_array($map) && isset($map[$focusAccount]) && is_array($map[$focusAccount])) {
                return sprintf('%s · цей об’єкт: %s → %s', $row->getSummary(), self::text($map[$focusAccount][0] ?? null), self::text($map[$focusAccount][1] ?? null));
            }
        }

        return $row->getSummary();
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'так' : 'ні',
            is_array($value) => (string)json_encode($value, \JSON_UNESCAPED_UNICODE),
            default => (string)$value,
        };
    }
}
