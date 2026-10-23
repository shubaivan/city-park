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
 * phone number arrives in. «У» folds to «Y» for the same reason: it is the glyph somebody
 * reaches for on a Cyrillic keyboard when the plate says Y.
 *
 * **Any Latin letter and any length from 4 to 10 is accepted** (20.10.2026). For a few
 * hours only the standard «СА 1234 АВ» shape with the twelve plate letters passed, and two
 * residents wrote in the same afternoon that they could not add their car — one of them
 * because it is electric, whose series uses Latin letters outside those twelve. Foreign and
 * individual plates are real here too. A list nobody can get onto is worth less than one
 * with the odd typo, and the admin can remove a wrong number.
 *
 * **A Cyrillic letter with no Latin twin is still refused, never dropped.** Stripping it
 * would turn «СЖ 1234» into «C1234» — a different plate, and a confident wrong match. The
 * refusal names the letter ({@see problem()}), so the person knows what to change.
 */
final class PlateKey
{
    private const CYRILLIC_TO_LATIN = [
        'А' => 'A', 'В' => 'B', 'С' => 'C', 'Е' => 'E', 'Н' => 'H', 'І' => 'I',
        'К' => 'K', 'М' => 'M', 'О' => 'O', 'Р' => 'P', 'Т' => 'T', 'Х' => 'X',
        'У' => 'Y',
    ];

    public const MIN_LENGTH = 4;
    public const MAX_LENGTH = 10;

    public const ONE_AT_A_TIME = 'Якщо авто кілька — надсилайте по одному номеру: спершу один, '
        . 'а наступний — кнопкою «➕ Додати номер авто».';

    /** The stored form, or '' when this is not something a plate can be. */
    public static function of(?string $plate): string
    {
        $plate = self::pick($plate);

        return self::problem($plate) === null ? self::fold($plate) : '';
    }

    /**
     * Why this cannot be stored, in words for the person who typed it — or null if it can.
     *
     * One reason, the first that applies: the person is holding a keyboard and needs to
     * know what to change, not a list of rules.
     */
    public static function problem(?string $plate): ?string
    {
        $plate = self::pick($plate);
        $key = self::fold($plate);

        if ($key === '') {
            return 'Напишіть номер авто.';
        }

        // Two cars in one message, one per line — the commonest refusal on the first day:
        // three people out of three sent theirs this way, were told the number was too long,
        // and the one who did not guess why gave up. Folded together it is one sixteen-
        // character «plate», so this has to be asked of the text as it was typed.
        if (self::severalPlates($plate)) {
            return self::ONE_AT_A_TIME;
        }

        if (preg_match('/\p{Cyrillic}/u', $key, $m) === 1) {
            return sprintf(
                'Літери «%s» на номерних знаках не буває. Напишіть номер так, як на табличці — '
                    . 'українськими чи латинськими літерами.',
                $m[0],
            );
        }

        if (preg_match('/[^A-Z0-9]/u', $key) === 1) {
            return 'Лише літери й цифри номера, без інших слів і знаків.';
        }

        $length = strlen($key);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return $length > self::MAX_LENGTH
                ? sprintf('У номері не більше %d літер і цифр. %s', self::MAX_LENGTH, self::ONE_AT_A_TIME)
                : sprintf('У номері від %d до %d літер і цифр.', self::MIN_LENGTH, self::MAX_LENGTH);
        }

        // Every plate carries both; a bare word or a bare number is something else typed here.
        if (preg_match('/\d/', $key) !== 1 || preg_match('/[A-Z]/', $key) !== 1) {
            return 'У номері мають бути і літери, і цифри.';
        }

        return null;
    }

    /**
     * The one line that is the number, when the message carries more than the number.
     *
     * «СА1322КН / Mazda CX5 / Буд 25, кв 25» — the car the way somebody describes it to a
     * person (23.10.2026). Since any 4–10 letters and digits pass, «Mazda CX5» is itself a
     * valid plate, so the message read as two cars and was refused with «надсилайте по
     * одному» to somebody who has one. Exactly one line in the standard «AA 1234 AA» shape
     * is unambiguous: that is the number, the rest is the model and the address. Two such
     * lines are two cars and still go back to the person; none leaves the text as typed.
     */
    private static function pick(?string $plate): string
    {
        $parts = preg_split('/[\r\n,;]+/u', (string)$plate, -1, PREG_SPLIT_NO_EMPTY);

        if (count($parts) < 2) {
            return (string)$plate;
        }
        $standard = array_values(array_filter(
            $parts,
            static fn (string $part): bool => preg_match('/^[A-Z]{2}\d{4}[A-Z]{2}$/', self::fold($part)) === 1,
        ));

        return count($standard) === 1 ? $standard[0] : (string)$plate;
    }

    /** More than one line (or comma-separated part) that would each be a plate on its own. */
    private static function severalPlates(?string $plate): bool
    {
        $parts = preg_split('/[\r\n,;]+/u', (string)$plate, -1, PREG_SPLIT_NO_EMPTY);

        // Also what keeps the problem() call below from coming back here for ever.
        if (count($parts) < 2) {
            return false;
        }
        $plates = array_filter($parts, static fn (string $part): bool => trim($part) !== '' && self::problem($part) === null);

        return count($plates) >= 2;
    }

    private static function fold(?string $plate): string
    {
        $key = mb_strtoupper(trim((string)$plate), 'UTF-8');
        $key = strtr($key, self::CYRILLIC_TO_LATIN);

        // What people put between the parts of a number, and nothing else.
        return (string)preg_replace('/[\s\-\.·_\/]+/u', '', $key);
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
