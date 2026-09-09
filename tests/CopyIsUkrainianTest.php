<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The bot speaks Ukrainian to residents. Every word of it.
 *
 * Not a style rule: this is an ОСББ in Черкаси writing to 457 of its own residents, and a
 * Russian word in the middle of a Ukrainian sentence reads as carelessness at best. The
 * slips found on 09.09.2026 were all on the screens somebody sees *first* — «Подтрібно
 * натиснути» over the phone button (half a word of each language, in neither), and
 * «Removing keyboard...» in English right after a resident confirms their number.
 *
 * They survived because each is a single word inside otherwise correct copy: nothing reads
 * the whole bot end to end, and a reviewer skims past «аккаунт» in a sentence that is
 * otherwise fine. So the check is mechanical.
 *
 * **Comments are stripped before scanning** — the notes explaining these very fixes quote
 * the words they removed, and a test that cannot survive its own explanation is a test
 * somebody deletes.
 */
class CopyIsUkrainianTest extends TestCase
{
    /**
     * Word => what it should have been. Deliberately short: each entry is something that
     * actually reached a resident, not a dictionary of everything Russian.
     */
    private const FORBIDDEN = [
        'аккаунт' => 'акаунт (українською — одне «к»)',
        'Подтрібно' => 'Потрібно',
        'телефона' => 'телефону',
        'Removing keyboard' => 'нічого: клавіатуру знімає сама відповідь',
        'Нажмите' => 'Натисніть',
        'Пожалуйста' => 'Будь ласка',
        'Отправьте' => 'Надішліть',
        'Спасибо' => 'Дякуємо',
    ];

    /** @return iterable<string, array{string}> */
    public static function files(): iterable
    {
        $root = dirname(__DIR__);

        foreach (['src', 'templates'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));

            foreach ($it as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'twig'], true)) {
                    continue;
                }

                // Migrations are not copy — they are historical SQL and are never read by
                // anybody but us.
                if (str_contains($file->getPathname(), '/src/Migrations/')) {
                    continue;
                }

                yield substr($file->getPathname(), strlen($root) + 1) => [$file->getPathname()];
            }
        }
    }

    /** @dataProvider files */
    public function testNoRussianWordsReachAResident(string $path): void
    {
        $source = (string)file_get_contents($path);
        $text = str_ends_with($path, '.php') ? self::withoutPhpComments($source) : self::withoutTwigComments($source);

        foreach (self::FORBIDDEN as $wrong => $right) {
            $this->assertStringNotContainsString(
                $wrong,
                $text,
                sprintf('%s: «%s» — має бути «%s».', basename($path), $wrong, $right),
            );
        }
    }

    private static function withoutPhpComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private static function withoutTwigComments(string $source): string
    {
        return (string)preg_replace('/\{#.*?#\}/s', '', $source);
    }
}
