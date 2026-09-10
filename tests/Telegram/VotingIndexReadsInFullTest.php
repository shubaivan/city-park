<?php

namespace App\Tests\Telegram;

use App\Entity\BlockVoteCampaign;
use App\Telegram\Voting\Command\VotingMenuCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The index of open votes is read before anything is tapped, and a question is the one
 * thing on it that cannot survive being cut.
 *
 * It lived on the button until 10.09.2026 — «до 16.09 · ❓ Чи погоджуєтеся ви на
 * встановлення обла…» — and a button is one line whatever we put in it: Telegram truncates
 * it to the width of the phone. The reader was left choosing between two halves of two
 * sentences, and «не зрозуміло, що це і за що» is what that produces.
 */
class VotingIndexReadsInFullTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(__DIR__ . '/../../src/Telegram/Voting/Command/VotingMenuCommand.php');
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod(VotingMenuCommand::class, $method);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    private function question(): BlockVoteCampaign
    {
        return (new BlockVoteCampaign())->setKind(BlockVoteCampaign::KIND_QUESTION);
    }

    private function block(): BlockVoteCampaign
    {
        return (new BlockVoteCampaign())->setKind(BlockVoteCampaign::KIND_BLOCK);
    }

    /**
     * The button carries the numeral, never the question — otherwise the truncation is
     * back, silently, the next time somebody "improves" the label.
     */
    public function testTheButtonDoesNotCarryTheQuestion(): void
    {
        $source = $this->source();
        $start = strpos($source, 'private function buttonLabel(');
        $this->assertNotFalse($start, 'buttonLabel is gone — this test needs rewriting, not deleting.');

        $body = substr($source, $start, 700);

        $this->assertStringNotContainsString(
            'getQuestion()',
            $body,
            'The question is back on the button, where Telegram cuts it to the width of the phone.',
        );
        $this->assertStringNotContainsString(
            'mb_substr',
            $body,
            'A truncated label on this board is a half-read question.',
        );
    }

    /** Both dates: «до 16.09» alone cannot tell this morning's vote from a week-old one. */
    public function testTheIndexNamesWhenItOpenedAndWhenItCloses(): void
    {
        $source = $this->source();
        $start = strpos($source, 'private function entry(');
        $this->assertNotFalse($start, 'entry() is what draws a row of the index.');

        $body = substr($source, $start, 900);

        $this->assertStringContainsString('getCreatedAt()', $body, 'The index does not say when the vote was posted.');
        $this->assertStringContainsString('getDeadlineAt()', $body, 'The index does not say when the vote closes.');
        $this->assertStringContainsString('розміщено', $body, 'The opening date is printed without saying what it is.');
    }

    /**
     * A screen headed «Голосування» reads as the bot deciding something. A question decides
     * nothing — but the sentence saying so must not be printed over a block campaign, which
     * blocks somebody for 30 days the moment it passes.
     */
    public function testItSaysAQuestionEnactsNothing(): void
    {
        $onlyQuestions = (string)$this->call('whatThisIs', [$this->question(), $this->question()]);

        $this->assertStringContainsString('нічого не ухвалює', $onlyQuestions);
        $this->assertStringContainsString('ОСББ', $onlyQuestions, 'It must say who does decide.');
    }

    public function testItDoesNotPromiseThatOverABlockCampaign(): void
    {
        $mixed = (string)$this->call('whatThisIs', [$this->question(), $this->block()]);

        $this->assertStringContainsString('блокування', $mixed);
        $this->assertStringNotContainsString(
            'нікому нічого не нараховує',
            $mixed,
            'That promise is false on a page carrying a block campaign.',
        );
    }

    /** The numeral ties a row of the text to the button under it. */
    public function testEveryRowOfAFullPageGetsItsOwnNumeral(): void
    {
        $seen = [];

        for ($i = 1; $i <= 8; $i++) {
            $seen[] = (string)$this->call('numeral', $i);
        }

        $this->assertCount(8, array_unique($seen), 'Two rows would answer to the same number.');
    }
}
