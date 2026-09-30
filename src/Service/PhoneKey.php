<?php

namespace App\Service;

/**
 * The one way a phone number is reduced to something two records can be compared on.
 *
 * Numbers reach this codebase in every shape the people who typed them use: Telegram
 * reports a shared contact as `380932729951`, the accountant's registry carries
 * `+380 93 272 99 51`, and a number typed into the panel by hand is as often `0932729951`.
 * All three are the same phone, and every place that has to recognise a person by it —
 * the conditional phones of a family member, the register of residents the ОСББ expects
 * on an object — has to agree on that or the recognition silently fails.
 *
 * The last nine digits are what all Ukrainian shapes share (operator code + number), and
 * anything shorter than nine is not a phone at all, so it matches nothing rather than
 * matching everything: an empty key compared with `LIKE '%%'` would hand the first flat
 * in the register to whoever typed a blank.
 *
 * Extracted from TelegramUserRepository on 21.09.2026, where it had been private — a
 * second copy of this rule is a copy that gets fixed once.
 */
final class PhoneKey
{
    /** Ukrainian numbers agree on their last nine digits whatever prefix they were written with. */
    public const LENGTH = 9;

    public static function of(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string)$phone);

        return strlen($digits) >= self::LENGTH ? substr($digits, -self::LENGTH) : '';
    }

    /** Both sides normalised, and a number too short to be one never matches anything. */
    public static function same(?string $a, ?string $b): bool
    {
        $left = self::of($a);

        return $left !== '' && $left === self::of($b);
    }

    /**
     * Is this actually a Ukrainian number?
     *
     * `of()` answers «which subscriber is this» and is deliberately forgiving, because the
     * registry holds every shape a human types. This answers a different question — «may
     * we treat the last nine digits as a Ukrainian subscriber» — and it has to be strict,
     * because the caller that needs it is the one that puts `380` back in front of them.
     *
     * Found on prod 21.09.2026: three residents carry foreign numbers, and one of them is
     * linked to a flat — `79595221999`, a `+7 959` mobile from occupied Luhansk. Its last
     * nine digits are `595221999`, so the friendly conversion turned it into
     * `380595221999`, a real Ukrainian number belonging to a stranger. A debt reminder
     * naming somebody's flat and sum would have gone to them. The Polish `48796496316`
     * does the same thing.
     *
     * Accepted: `380XXXXXXXXX`, `0XXXXXXXXX`, and a bare nine digits — the three shapes
     * the ОСББ's own records actually use.
     */
    public static function isUkrainian(?string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', (string)$phone);

        return match (strlen($digits)) {
            12 => str_starts_with($digits, '380'),
            10 => str_starts_with($digits, '0'),
            self::LENGTH => true,
            default => false,
        };
    }
}
