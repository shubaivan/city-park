<?php

namespace App\Tests\Telegram;

use App\Telegram\Voting\Command\VoteAsk;
use App\Telegram\Voting\Command\VotingMenuCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The two things the voting menu must not lose: a way to re-read the count, and a way to
 * find out from the main menu that there is anything to read.
 */
class VotingMenuTest extends TestCase
{
    private function config(): string
    {
        return (string)file_get_contents(__DIR__ . '/../../config/telegram.php');
    }

    private function source(string $path): string
    {
        return (string)file_get_contents(__DIR__ . '/../../' . $path);
    }

    /**
     * Every button this menu draws must reach a handler.
     *
     * An unrouted callback errors nowhere: Telegram spins the button and gives up, which
     * from the resident's side is the bot being down. The same guard the rental,
     * complaints and services menus carry — this one is added because the refresh button
     * is a *new* callback and adding one to the class without adding the line to
     * config/telegram.php is a one-line mistake with no symptom in any log.
     */
    public function testEveryCallbackTheMenuDrawsIsRouted(): void
    {
        $config = $this->config();

        // The public constants are the ones the config wires by name. The private
        // NOOP_CALLBACK is covered by the `vote:` regex below, with the concatenated ones.
        foreach ((new ReflectionClass(VotingMenuCommand::class))->getReflectionConstants() as $constant) {
            $name = $constant->getName();
            $value = $constant->getValue();

            if (!$constant->isPublic() || !str_contains($name, 'CALLBACK')) {
                continue;
            }

            $this->assertStringContainsString(
                'VotingMenuCommand::' . $name,
                $config,
                sprintf(
                    '%s (%s) is a button this menu draws and nothing routes it — it will just spin.',
                    $name,
                    (string)$value,
                ),
            );
        }

        // The ones built by concatenation, which no constant can stand for.
        foreach (['^bvote:\d+:(yes|no)$', '^vote:(?:drop:\d+|noop)$'] as $pattern) {
            $this->assertStringContainsString($pattern, $config);
        }

        $this->assertStringContainsString('VoteAsk::START_CALLBACK', $config);
    }

    /**
     * A refresh that changes nothing must answer with a toast, never with a second menu.
     *
     * Telegram refuses an edit that would leave the message identical, and on a refresh
     * button that is the *common* case — most taps happen when nobody has voted since.
     * `respond()` falls through to `sendMessage()` on any edit failure, which is right
     * everywhere else and would here post one more copy of the whole menu per tap.
     */
    public function testARefreshThatChangesNothingDoesNotPostASecondMenu(): void
    {
        $source = $this->source('src/Telegram/Voting/Command/VotingMenuCommand.php');

        $this->assertStringContainsString(
            "str_contains(\$e->getMessage(), 'not modified')",
            $source,
            'the refresh path must recognise Telegram refusing an identical edit',
        );

        $this->assertMatchesRegularExpression(
            '/not modified\'\)\) \{.*?return;.*?\}.*?\$bot->sendMessage/s',
            $source,
            'the unchanged-refresh branch must return before the sendMessage fallback',
        );
    }

    /**
     * The main menu says when the house is deciding something.
     *
     * Without a count on the button, an open vote is invisible to anybody who does not
     * open the section or read the chat post — and a vote ends on a count, so the
     * residents it misses are the ballots it needed. `MenuContainerLookupsTest` covers the
     * other half (the service has to be public or the count silently disappears).
     */
    /**
     * With two votes open, every block and every button says which one it is.
     *
     * The keyboard hangs at the bottom of a single message, screens away from the question
     * it belongs to. On 09.09.2026 the menu held two questions and the reader saw «👍 За /
     * 👎 Проти» once and «✅ Ви проголосували: За» once, with nothing to say which row
     * answered which — «непонятно где какое». Numbering the block and its buttons with the
     * same glyph is what ties them together, so a button that loses the mark is the bug
     * coming back.
     */
    public function testEveryVoteButtonCarriesTheNumberOfItsQuestion(): void
    {
        $source = $this->source('src/Telegram/Voting/Command/VotingMenuCommand.php');

        $this->assertStringContainsString(
            'private static function numberBadge(',
            $source,
            'nothing numbers the votes any more',
        );

        // Each button the menu draws per campaign: the two ballots and the cast pill.
        preg_match_all(
            '/InlineKeyboardButton::make\((.{0,180}?)callback_data: (?:\x27bvote:\x27|self::NOOP_CALLBACK)/s',
            $source,
            $matches,
        );

        $this->assertGreaterThanOrEqual(
            6,
            count($matches[1]),
            'expected both kinds of campaign to draw yes / no / cast buttons',
        );

        foreach ($matches[1] as $label) {
            $this->assertStringContainsString(
                '$mark',
                $label,
                'a vote button without its number: with two votes open nobody can tell which question it answers — ' . trim($label),
            );
        }
    }

    public function testTheMenuButtonCarriesTheNumberOfOpenVotes(): void
    {
        $start = $this->source('src/Telegram/Start/Command/StartCommand.php');

        $this->assertStringContainsString(
            "InlineKeyboardButton::make(self::votingLabel(\$bot, \$account), callback_data: 'voting-menu')",
            $start,
            'the voting row must render the counted label, not a fixed string',
        );

        $this->assertStringContainsString("sprintf('🗳️ Голосування (%d)', \$open)", $start);
    }

    /**
     * The badge counts votes that are open, not votes the reader still owes.
     *
     * A vote here is final, so a "todo" badge would disappear the moment somebody voted —
     * taking with it the only thing on the main menu saying a vote is running, and with it
     * any chance that they mention it to a neighbour who has not voted. Same reading as
     * «🔧 Заявки (5)» and «🛠 Послуги (3)».
     */
    public function testTheBadgeDoesNotVanishOnceTheReaderHasVoted(): void
    {
        $service = $this->source('src/Service/BlockVoteService.php');

        preg_match('/public function openVoteCount\(.*?\n    \}/s', $service, $match);
        $this->assertNotEmpty($match, 'openVoteCount() is what the menu badge counts with');

        $this->assertStringNotContainsString(
            'ballotRepository',
            $match[0],
            'the count must not depend on whether this account already voted',
        );
    }
}
