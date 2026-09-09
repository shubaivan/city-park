<?php

namespace App\Tests\Service;

use App\Service\BlockVoteService;
use App\Telegram\Info\Command\InfoCommand;
use App\Telegram\Voting\Command\VotingMenuCommand;
use PHPUnit\Framework\TestCase;

/**
 * What the bot tells 177 people when a vote opens.
 *
 * This message is the vote for most of the house — it is a push, it arrives once, and
 * almost nobody who reads it will open the section afterwards to check whether it was
 * accurate.
 */
class VoteNoticeTest extends TestCase
{
    private function source(string $path): string
    {
        return (string)file_get_contents(__DIR__ . '/../../src/' . $path);
    }

    /**
     * The notice leads to the vote it is about.
     *
     * It used to end «меню «🗳️ Голосування» або команда /vote» — which was fair while the
     * section was one message holding everything, and became a dead end the moment votes
     * got a card each: the reader is told about one question and asked to go find it. Same
     * rule, and the same fix, as «↗️ Відкрити в боті» under a chat post.
     */
    public function testTheOpenedNoticeCarriesAButtonToThatVote(): void
    {
        $source = $this->source('Service/BlockVoteService.php');

        $this->assertStringContainsString(
            'VotingMenuCommand::CARD_PREFIX . $campaign->getId()',
            $source,
            'the notice must point at the vote it names, not at the section',
        );
        $this->assertStringContainsString(
            'reply_markup: $markup',
            $source,
            'the button has to reach sendMessage() to exist at all',
        );
        $this->assertTrue(
            defined(VotingMenuCommand::class . '::CARD_PREFIX'),
            'the card callback the notice builds must still exist',
        );
    }

    /**
     * Nothing may tell a resident their vote can be changed.
     *
     * A ballot has been final since 08.09.2026 — `recordVote()` refuses a second one — but
     * the notice and the FAQ still promised «свій вибір можна змінити до завершення», and
     * that promise went out to 177 people on 09.09.2026. Copy that contradicts the rule is
     * worse than no copy: it is the bot inviting a tap it will refuse.
     */
    public function testNoCopyPromisesAVoteCanBeChanged(): void
    {
        foreach ([
            'Service/BlockVoteService.php',
            'Telegram/Info/Command/InfoCommand.php',
            'Telegram/Voting/Command/VotingMenuCommand.php',
        ] as $file) {
            $this->assertStringNotContainsString(
                'можна змінити до завершення',
                $this->source($file),
                $file . ': a ballot is final and the copy must say so',
            );
        }

        $this->assertStringContainsString(
            'Голос остаточний',
            $this->source('Service/BlockVoteService.php'),
            'the notice must say the vote is final, where the old promise stood',
        );
    }
}
