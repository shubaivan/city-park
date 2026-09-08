<?php

namespace App\Tests\Service;

use App\Entity\BlockVoteCampaign;
use App\Service\BlockVoteService;
use App\Service\DebtPolicy;
use PHPUnit\Framework\TestCase;

/**
 * **Rule: a number the bot uses against somebody must show where it came from.**
 *
 * «Поріг блокування: 1 024.65 грн» is unarguable and unverifiable at the same time. It
 * reads as a figure chosen for this flat, and the first thing a person does with a number
 * they cannot check is ring the accountant to dispute it — so the message that was supposed
 * to end a conversation starts one. The sum is arithmetic on two things the resident already
 * knows, their own area and the published tariff, and printing it turns an accusation into a
 * receipt: «50.6 м² × 13.50 грн/м² × 1.5».
 *
 * The same applies to the vote threshold, which is why it says «потрібно N із M» rather than
 * a bare percentage.
 *
 * This is not decoration. Both numbers decide whether somebody may use the альтанка.
 */
class NumbersExplainThemselvesTest extends TestCase
{
    /** The block message shows the multiplication, not just the product. */
    public function testTheBlockThresholdShowsItsArithmetic(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../src/Service/BlockReasonResolver.php');

        $this->assertStringContainsString(
            'thresholdExplained(',
            $source,
            'the block message must say where its threshold came from',
        );

        $this->assertStringContainsString(
            'DebtPolicy::OVER_FACTOR',
            $source,
            'the factor must be read from the policy, not retyped into the copy',
        );

        // And it must stay silent rather than lie when the formula was not what produced
        // the number: getThresholdFor() falls back to a flat sum when the area or the
        // tariff is missing.
        $this->assertStringContainsString(
            'if ($area <= 0 || $price <= 0) {',
            $source,
            'a fallback threshold must not be explained with numbers that did not make it',
        );
    }

    /** 1.5 is «півтори місячні нарахування» — the copy and the constant must agree. */
    public function testTheFactorInTheCopyIsTheFactorInTheCode(): void
    {
        $this->assertSame(1.5, DebtPolicy::OVER_FACTOR);
    }

    /**
     * The vote threshold is a fraction of the people who can actually vote.
     *
     * Counting objects with nobody behind them makes every one of them a vote the campaign
     * needs and can never receive. `objects:import-registry` took the electorate from 112 to
     * 774 while about 180 have a linked resident — at 30% that is 233 needed out of ~180
     * possible, so no campaign could ever pass, and nothing said so because the button still
     * worked.
     */
    public function testOnlyObjectsWithSomebodyBehindThemCanVote(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../src/Service/BlockVoteService.php');

        $this->assertStringContainsString(
            'getUsers()->isEmpty()',
            $source,
            'an object nobody is linked to cannot vote and must not be counted as if it could',
        );

        // The roll call and the ballot check must use one definition, or a voter is counted
        // but refused, or refused but counted.
        $this->assertSame(
            2,
            substr_count($source, 'self::mayVote('),
            'eligibleVoters() and isEligibleVoter() must ask the same question',
        );
    }

    /** «Потрібно N із M», never a bare percentage: N is what a neighbour can count. */
    public function testThePassThresholdIsSpelledOutAsACount(): void
    {
        $campaign = new BlockVoteCampaign();
        $campaign->setEligibleCount(180);

        // floor(180 × 0.30) + 1 — strictly more than the fraction, and a whole number of
        // neighbours rather than a percentage nobody can hold in their head.
        $this->assertSame(55, $campaign->yesNeeded());
    }
}
