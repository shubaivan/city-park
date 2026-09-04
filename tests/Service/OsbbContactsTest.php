<?php

namespace App\Tests\Service;

use App\Service\OsbbContacts;
use PHPUnit\Framework\TestCase;

/**
 * The two people a resident is ever told to contact.
 *
 * The sentence was copy-pasted into twelve files, which meant twelve plain phone numbers:
 * a number in text cannot be tapped into a chat, and somebody reading a block notice on a
 * phone at 22:40 will neither write it down nor dial it. It also meant that the day one of
 * them changes their number, eleven messages still send people to the old one.
 */
class OsbbContactsTest extends TestCase
{
    public function testBothContactsAreTappableToCallAndToWrite(): void
    {
        foreach ([OsbbContacts::ACCOUNTANT_LINE, OsbbContacts::CHAIR_LINE] as $line) {
            $this->assertStringContainsString('href="tel:+380', $line, 'a phone must dial');
            $this->assertStringContainsString('https://t.me/+380', $line, 'and open a chat');
            $this->assertStringContainsString('написати в Telegram', $line);
        }
    }

    /**
     * `t.me/+<digits>` rather than a @username: the head of the ОСББ has none, and a link
     * that works for one of them and not the other is worse than one shape for both.
     */
    public function testTheChatLinkUsesTheSameDigitsAsTheDialLink(): void
    {
        foreach ([OsbbContacts::ACCOUNTANT_LINE, OsbbContacts::CHAIR_LINE] as $line) {
            preg_match('/href="tel:\+(\d+)"/', $line, $tel);
            preg_match('#https://t\.me/\+(\d+)#', $line, $chat);

            $this->assertNotEmpty($tel[1] ?? null);
            $this->assertSame($tel[1], $chat[1] ?? null);
        }
    }

    public function testTheDigitsMatchThePublishedNumbers(): void
    {
        $this->assertStringContainsString(
            preg_replace('/\D+/', '', OsbbContacts::ACCOUNTANT_PHONE),
            OsbbContacts::ACCOUNTANT_LINE,
        );
        $this->assertStringContainsString(
            preg_replace('/\D+/', '', OsbbContacts::CHAIR_PHONE),
            OsbbContacts::CHAIR_LINE,
        );
    }

    /**
     * Usable inside a `const` array — the FAQ keeps its whole text in one, and PHP constant
     * expressions cannot call a method. That is why these are constants and the methods
     * merely return them.
     */
    public function testTheLinesAreConstantExpressions(): void
    {
        $this->assertSame(OsbbContacts::ACCOUNTANT_LINE, OsbbContacts::accountant());
        $this->assertSame(OsbbContacts::CHAIR_LINE, OsbbContacts::chair());
        $this->assertSame(
            OsbbContacts::ACCOUNTANT_LINE . "\n" . OsbbContacts::CHAIR_LINE,
            OsbbContacts::both(),
        );
    }

    /** No message may keep its own copy of a number that lives in one place now. */
    public function testNoOtherFileHardcodesThePhones(): void
    {
        $root = __DIR__ . '/../../src';
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'OsbbContacts.php')) {
                continue;
            }

            $body = (string)file_get_contents($file->getPathname());

            if (str_contains($body, '93 658 32 02') || str_contains($body, '67 470 46 24')) {
                $offenders[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'these still carry their own copy of a phone number');
    }
}
