<?php

namespace App\Tests\Service;

use App\Entity\BlockVoteCampaign;
use PHPUnit\Framework\TestCase;

/**
 * A question put to the house is the same vote as a block campaign, and a different act.
 *
 * Same machinery — who may vote, one ballot per account, the snapshotted denominator, the
 * deadline, the broadcast, the tally cron, the archive — because two entities would be two
 * copies of that and one would rot. Same call the rental board made about rent and sale.
 *
 * What must not be shared is the *consequence*. A block crosses a threshold and something
 * happens to a named household. A question decides nothing on its own: it records what the
 * house answered and the ОСББ acts. These tests exist because "make the two kinds
 * consistent" is a tempting and wrong tidy-up.
 */
class HouseQuestionRulesTest extends TestCase
{
    private function question(): BlockVoteCampaign
    {
        return (new BlockVoteCampaign())
            ->setKind(BlockVoteCampaign::KIND_QUESTION)
            ->setQuestion('Чи встановлюємо шлагбаум на в’їзді?')
            ->setEligibleCount(180);
    }

    /**
     * No threshold. «Потрібно 55 голосів» beside something that enacts nothing is a promise
     * the bot cannot keep, and the first person to reach 55 would reasonably expect a
     * barrier to appear.
     */
    public function testAQuestionHasNoPassThreshold(): void
    {
        $this->assertSame(0, $this->question()->yesNeeded());

        $block = (new BlockVoteCampaign())->setEligibleCount(180);
        $this->assertSame(55, $block->yesNeeded(), 'a block campaign still has one');
    }

    /** It closes; it does not «pass» or «fail». */
    public function testAFinishedQuestionIsRecordedNotJudged(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../src/Service/BlockVoteService.php');

        $this->assertStringContainsString(
            'if ($campaign->isQuestion()) {',
            $source,
            'closeExpiredCampaigns must not put a question through the block branch',
        );
        $this->assertStringContainsString(
            'BlockVoteCampaign::STATUS_CLOSED',
            $source,
            'a question ends as «closed» — «passed» would claim a mandate nobody gave',
        );
    }

    /** And nothing can block anybody off the back of one. */
    public function testAQuestionCanNeverBlockAnybody(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../src/Service/BlockVoteService.php');

        $this->assertStringContainsString(
            '$campaign->isBlock() && $tally[\'yes\'] >= $campaign->yesNeeded()',
            $source,
            'an early close on the threshold must be a block campaign only',
        );
        $this->assertStringContainsString(
            'if (!$account instanceof Account || !$campaign->isBlock()) {',
            $source,
            'applyBlock must refuse a question outright, whatever called it',
        );
    }

    /** Subject and candidate are different questions; a question has no candidate at all. */
    public function testTheSubjectIsWhateverTheVoteIsAbout(): void
    {
        $this->assertSame('Чи встановлюємо шлагбаум на в’їзді?', $this->question()->subject());
        $this->assertNull($this->question()->getCandidate());
        $this->assertTrue($this->question()->isQuestion());
        $this->assertFalse($this->question()->isBlock());
    }

    /** An unknown kind falls back to a block rather than becoming a third, unhandled one. */
    public function testAnUnknownKindIsNotInvented(): void
    {
        $campaign = (new BlockVoteCampaign())->setKind('referendum');

        $this->assertSame(BlockVoteCampaign::KIND_BLOCK, $campaign->getKind());
    }
}
