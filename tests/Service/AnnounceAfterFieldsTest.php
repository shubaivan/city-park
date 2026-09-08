<?php

namespace App\Tests\Service;

use App\Service\BlockVoteService;
use PHPUnit\Framework\TestCase;

/**
 * A vote is announced only once the row is complete.
 *
 * `openCampaign()` used to publish the chat post itself, which put the post **before**
 * `openQuestion()` had written the question into the row — so the house received «🗳
 * Питання до мешканців», a deadline, a footer, and nothing in between. Nobody could tell
 * what was being asked, and the DMs were fine, which made it look like a rendering glitch
 * rather than an ordering one.
 *
 * The fix is structural rather than a re-ordered line: creating a campaign no longer
 * announces anything at all, and `broadcast()` is the only thing that does. A caller that
 * forgets it sends nothing — visible and harmless — instead of sending an empty post.
 */
class AnnounceAfterFieldsTest extends TestCase
{
    public function testCreatingACampaignDoesNotAnnounceIt(): void
    {
        $source = self::method('openCampaign');

        $this->assertStringNotContainsString(
            '$this->announce(',
            $source,
            'openCampaign() must not publish: the row is not finished when it runs',
        );
        $this->assertStringNotContainsString(
            'VoteBroadcastMessage',
            $source,
            'and must not queue the DMs either — broadcast() owns both halves',
        );
    }

    /** The question is written before anything is sent. */
    public function testTheQuestionIsSetBeforeTheBroadcast(): void
    {
        $source = self::method('openQuestion');

        $setQuestion = strpos($source, 'setQuestion(');
        $broadcast = strpos($source, '$this->broadcast(');

        $this->assertNotFalse($setQuestion, 'openQuestion() must set the question');
        $this->assertNotFalse($broadcast, 'openQuestion() must broadcast when asked');
        $this->assertLessThan(
            $broadcast,
            $setQuestion,
            'the question must be on the row before the house is told about it',
        );
    }

    /** And broadcast() is what stamps the row as sent, so the two cannot drift apart. */
    public function testBroadcastOwnsBothTheStampAndTheSend(): void
    {
        $source = self::method('broadcast');

        foreach (['setBroadcastAt(', '$this->announce(', 'VoteBroadcastMessage'] as $needle) {
            $this->assertStringContainsString($needle, $source, 'broadcast() must do ' . $needle);
        }
    }

    private static function method(string $name): string
    {
        $reflection = new \ReflectionMethod(BlockVoteService::class, $name);
        $lines = file((string)$reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
