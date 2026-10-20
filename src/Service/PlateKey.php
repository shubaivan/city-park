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
 * Latin letters pass as they are, because a car registered abroad is still a resident's car.
 */
final class PlateKey
{
    private const CYRILLIC_TO_LATIN = [
        'А' => 'A', 'В' => 'B', 'С' => 'C', 'Е' => 'E', 'Н' => 'H', 'І' => 'I',
        'К' => 'K', 'М' => 'M', 'О' => 'O', 'Р' => 'P', 'Т' => 'T', 'Х' => 'X',
    ];

    public const MIN_LENGTH = 4;
    public const MAX_LENGTH = 10;

    /** The stored form, or '' when this is not something a plate can be. */
    public static function of(?string $plate): string
    {
        $key = mb_strtoupper(trim((string)$plate), 'UTF-8');
        $key = strtr($key, self::CYRILLIC_TO_LATIN);
        // What people put between the parts of a number, and nothing else.
        $key = (string)preg_replace('/[\s\-\.·]+/u', '', $key);

        if (preg_match('/^[A-Z0-9]{' . self::MIN_LENGTH . ',' . self::MAX_LENGTH . '}$/', $key) !== 1) {
            return '';
        }

        // Every plate carries digits; a word that happens to fit is a typo for something else.
        return preg_match('/\d/', $key) === 1 ? $key : '';
    }

    /**
     * «CA 1234 AB» for the standard Ukrainian shape, the bare key otherwise.
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
