<?php

namespace App\Service;

/**
 * One spelling of a car number, whatever alphabet it was typed in.
 *
 * A Ukrainian plate uses twelve letters, and every one of them has a Latin twin that looks
 * identical: «СА 1234 АВ» arrives as Cyrillic from one phone and as Latin from the next,
 * often mixed inside one number. Compared as typed, the same car is two cars — the base
 * looks full and the guard's lookup finds nothing. So everything is folded into Latin
 * before it is stored or compared, the same job `PhoneKey` does for the four shapes a
 * phone number arrives in.
 *
 * **A letter outside those twelve is refused, never dropped.** Stripping whatever is not
 * A–Z would turn «СЖ 1234» into «C1234» — a different plate, and a confident wrong match.
 *
 * **Only the standard shape is accepted: two letters, four digits, two letters**
 * (Иван, 20.10.2026). The first version took anything of 4–10 letters and digits with a
 * digit in it, so a list meant to feed an access system would have filled with «AB123»,
 * a missing letter, and a stray «1234» — and no gate can match a typo. The letters are the
 * twelve the plates use, so a Latin «D» or «Z» is a typo too. A foreign or individual plate
 * is refused, and the prompt says whom to write to; when there are enough of those to
 * matter, that is the moment to widen this, not before.
 */
final class PlateKey
{
    private const CYRILLIC_TO_LATIN = [
        'А' => 'A', 'В' => 'B', 'С' => 'C', 'Е' => 'E', 'Н' => 'H', 'І' => 'I',
        'К' => 'K', 'М' => 'M', 'О' => 'O', 'Р' => 'P', 'Т' => 'T', 'Х' => 'X',
    ];

    /** The plate letters, after folding. */
    private const LETTERS = 'ABCEHIKMOPTX';

    /** The stored form, or '' when this is not something a plate can be. */
    public static function of(?string $plate): string
    {
        $key = mb_strtoupper(trim((string)$plate), 'UTF-8');
        $key = strtr($key, self::CYRILLIC_TO_LATIN);
        // What people put between the parts of a number, and nothing else.
        $key = (string)preg_replace('/[\s\-\.·]+/u', '', $key);

        $letters = '[' . self::LETTERS . ']';

        return preg_match('/^' . $letters . '{2}\d{4}' . $letters . '{2}$/', $key) === 1 ? $key : '';
    }

    /**
     * «CA 1234 AB» for the standard Ukrainian shape, the bare key otherwise (only rows
     * stored before the shape was enforced can be anything else).
     *
     * Shown in Latin: the twelve letters are the same glyphs in either alphabet, so the
     * reader sees exactly the plate on the car.
     */
    public static function display(string $key): string
    {
        return preg_match('/^([A-Z]{2})(\d{4})([A-Z]{2})$/', $key, $m) === 1
            ? sprintf('%s %s %s', $m[1], $m[2], $m[3])
            : $key;
    }
}
