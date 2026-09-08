<?php

namespace App\Tests\Telegram;

use App\Service\DeepLink;
use PHPUnit\Framework\TestCase;

/**
 * **Every post the bot puts in the residents' chat must carry a way back into the bot.**
 *
 * The chat post is always a summary — it is short on purpose, it never carries the photos,
 * and on most boards it never carries a phone. Everything it leaves out lives on a card
 * the reader then has to go and find: leave the chat, open the bot, find the section, find
 * the one row among a list. Four taps and a guess, for something they were already looking
 * at. Every board shipped that dead end and every board had to have it removed afterwards.
 *
 * So it is a rule now, and this is what enforces it: a new board cannot ship a post that
 * ends in «details are in the bot somewhere». The check is on the source rather than on a
 * rendered message because these sends are scattered across services and most of them
 * cannot be exercised without a Telegram.
 *
 * If a post genuinely has nowhere to point — nothing in this bot yet — add it to
 * {@see self::NO_TARGET} with the reason, and the reason will be read by whoever adds the
 * next one.
 */
class ResidentChatPostsLinkBackTest extends TestCase
{
    /** Sends into the group that legitimately have no card to open. */
    private const NO_TARGET = [
        // The chat gate's own refusal, sent to a person, not to the group.
    ];

    /** @return array<string, array{string}> */
    public static function servicesThatPostToTheChat(): array
    {
        $root = __DIR__ . '/../../src/Service';

        return [
            'service offers' => [$root . '/ServiceOfferService.php'],
            'rental listings' => [$root . '/RentalListingService.php'],
            'complaints' => [$root . '/ComplaintService.php'],
            'the debtors\' board' => [$root . '/DebtAnnouncer.php'],
        ];
    }

    /**
     * @dataProvider servicesThatPostToTheChat
     */
    public function testEverySendIntoTheGroupCarriesADeepLink(string $file): void
    {
        $source = (string)file_get_contents($file);
        $name = basename($file, '.php');

        // `message_thread_id:` is the reliable marker: in this codebase only a post into
        // the residents' chat carries one, while the chat id itself is sometimes assigned
        // to a local first and would slip past a search for it.
        $calls = array_filter(
            self::calls($source, 'sendMessage('),
            static fn (string $call): bool => str_contains($call, 'message_thread_id:'),
        );

        $this->assertNotEmpty(
            $calls,
            $name . ': no send into the residents\' chat found — did it change shape?',
        );

        foreach ($calls as $call) {
            if (in_array(trim($call), self::NO_TARGET, true)) {
                continue;
            }

            $this->assertStringContainsString(
                'links->button(',
                $call,
                $name . ": a post into the residents' chat must carry «↗️ Відкрити в боті». "
                    . 'The post is a summary; everything it leaves out is on a card the reader '
                    . 'would otherwise have to hunt for. If this one truly has no target, add it '
                    . 'to ResidentChatPostsLinkBackTest::NO_TARGET with the reason.',
            );
        }
    }

    /**
     * Every `$needle` call in the source, from the opening paren to its own closing one.
     *
     * A regex cannot do this: these calls are multi-line and contain nested calls of their
     * own, and a lazy pattern stops at the first `)` it finds — which is inside
     * `chatId()`, not at the end of the send.
     *
     * @return string[]
     */
    private static function calls(string $source, string $needle): array
    {
        $found = [];
        $offset = 0;

        while (($start = strpos($source, $needle, $offset)) !== false) {
            $i = $start + strlen($needle);
            $depth = 1;

            while ($i < strlen($source) && $depth > 0) {
                $depth += match ($source[$i]) { '(' => 1, ')' => -1, default => 0 };
                $i++;
            }

            $found[] = substr($source, $start, $i - $start);
            $offset = $i;
        }

        return $found;
    }

    /** The link is built and read from one map, so a new kind cannot be half-added. */
    public function testEveryKindHasAPrefixAndNoTwoShareOne(): void
    {
        $prefixes = array_values(DeepLink::PREFIXES);

        $this->assertSame(
            count($prefixes),
            count(array_unique($prefixes)),
            'two kinds share a prefix — one of them would open the other',
        );

        foreach (DeepLink::PREFIXES as $kind => $prefix) {
            $this->assertMatchesRegularExpression('/^[a-z]-$/', $prefix, $kind . ' has an odd prefix');
        }
    }
}
