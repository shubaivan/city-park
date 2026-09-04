<?php

namespace App\Service;

/**
 * The two people a resident is ever told to contact, in one place.
 *
 * The same sentence — «Зверніться до Аліни Бухгалтера (+380 93 658 32 02) або голови ОСББ
 * Люди (+380 67 470 46 24)» — was copy-pasted into twelve files: the block notice, the
 * unblock notice, the photo-miss warnings, the vote result, the FAQ, the phone-approval
 * step. Two problems with that, and only one of them is tidiness:
 *
 * - **a phone number in text cannot be tapped into a chat.** It is a number to write down
 *   and dial, and a resident who reads it on a phone at 22:40 does neither. The one place
 *   that got this right — the main menu — has linked «написати в Telegram» since the head
 *   of the ОСББ has no @username and `t.me/+<phone>` is the only handle she has;
 * - twelve copies means the day one of them changes their number, eleven of them still
 *   send people to the old one.
 *
 * Rendered as HTML, because every caller sends with ParseMode::HTML.
 */
class OsbbContacts
{
    public const ACCOUNTANT_NAME = 'Аліна';
    public const ACCOUNTANT_PHONE = '+380 93 658 32 02';

    public const CHAIR_NAME = 'Людмила Осипенко';
    public const CHAIR_PHONE = '+380 67 470 46 24';

    /**
     * Constants, not method calls, because the FAQ keeps its whole text in a `const` array
     * and PHP constant expressions cannot call anything. Concatenating constants is
     * allowed, so the one definition still lives here and the FAQ still reads it.
     *
     * `t.me/+<digits>` rather than a @username on purpose: the head of the ОСББ has none
     * (the registry field is empty), and a link that works for one of them and not the
     * other is worse than one shape that works for both.
     */
    public const ACCOUNTANT_LINE = '🧾 Аліна, бухгалтер ОСББ — '
        . '<a href="tel:+380936583202">' . self::ACCOUNTANT_PHONE . '</a> · '
        . '<a href="https://t.me/+380936583202">написати в Telegram</a>';

    public const CHAIR_LINE = '👩‍💼 ' . self::CHAIR_NAME . ', голова ОСББ — '
        . '<a href="tel:+380674704624">' . self::CHAIR_PHONE . '</a> · '
        . '<a href="https://t.me/+380674704624">написати в Telegram</a>';

    public const BOTH_LINES = self::ACCOUNTANT_LINE . "\n" . self::CHAIR_LINE;

    /**
     * «Аліна Бухгалтер — +380 93 658 32 02 · написати», both tappable.
     *
     * `t.me/+<digits>` rather than a @username on purpose: the head of the ОСББ has none
     * (the registry field is empty), and a link that works for one of them and not the
     * other is worse than one shape that works for both.
     */
    public static function accountant(): string
    {
        return self::ACCOUNTANT_LINE;
    }

    public static function chair(): string
    {
        return self::CHAIR_LINE;
    }

    /** Both, one per line — for a message that ends with "who to ask". */
    public static function both(): string
    {
        return self::BOTH_LINES;
    }

    /**
     * The line that closes a block / unblock / photo-miss notice.
     *
     * A sentence rather than a list, because it arrives at the end of bad news and the
     * reader is looking for one thing: who do I write to now.
     */
    public static function askThem(string $lead = 'Зверніться для розблокування:'): string
    {
        return $lead . "\n" . self::both();
    }

}
