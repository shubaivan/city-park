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
     * The section is an index of buttons, and every one of them reaches a handler.
     *
     * Both halves earned this. The menu used to render every open vote in full into one
     * message with all their buttons stacked at the bottom: with two questions open
     * (09.09.2026) nobody could tell which row answered which, and at forty it is a message
     * no phone can read. And an unrouted callback errors nowhere — the button spins and
     * gives up, which from the resident's side is the bot being down.
     */
    public function testTheIndexIsButtonsAndEveryOneOfThemIsRouted(): void
    {
        $source = $this->source('src/Telegram/Voting/Command/VotingMenuCommand.php');
        $config = $this->config();

        foreach (['CARD_PREFIX', 'REFRESH_PREFIX', 'PAGE_PREFIX'] as $name) {
            $this->assertMatchesRegularExpression(
                '/public const ' . $name . " = 'vote:[a-z]+:';/",
                $source,
                $name . ' is how the index reaches one vote — it cannot be dropped',
            );
        }

        // The literals the buttons actually carry, as Telegram will send them back.
        preg_match_all("/public const (?:CARD|REFRESH|PAGE)_PREFIX = '([^']+)';/", $source, $prefixes);
        preg_match_all(
            "/onCallbackQueryData\(\s*'(\^vote:[^']+)'\s*,\s*\\\\App\\\\Telegram\\\\Voting/",
            $config,
            $patterns,
        );

        $this->assertNotEmpty($patterns[1], 'nothing in the config routes a vote: callback');

        foreach ($prefixes[1] as $prefix) {
            $sample = $prefix . '7';
            $routed = false;

            foreach ($patterns[1] as $pattern) {
                if (preg_match('/' . str_replace('/', '\\/', $pattern) . '/', $sample) === 1) {
                    $routed = true;
                    break;
                }
            }

            $this->assertTrue($routed, $sample . ' is a button nothing routes — it will just spin');
        }
    }

    /**
     * The index pages instead of growing, and the page number is clamped.
     *
     * «А якщо їх буде сорок» — one message cannot hold forty votes and their buttons, and a
     * callback from an older, longer list must not answer with an empty page. Same rule as
     * the debtors' board, which is written around exactly that.
     */
    public function testTheIndexPagesAndClampsThePageNumber(): void
    {
        $source = $this->source('src/Telegram/Voting/Command/VotingMenuCommand.php');

        $this->assertStringContainsString('private const PAGE_SIZE', $source);
        $this->assertStringContainsString('array_slice($campaigns, $offset, self::PAGE_SIZE)', $source);
        $this->assertStringContainsString('$page = max(1, min($page, $pages));', $source, 'the page number must be clamped, not trusted');
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
