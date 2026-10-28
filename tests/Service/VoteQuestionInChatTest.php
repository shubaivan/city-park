<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\BlockVoteCampaign;
use App\Entity\TelegramUser;
use App\Service\BlockVoteService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every question reaches the chat's «🗳 Голосування» topic the moment it is asked, and says
 * which flat asked it.
 *
 * A resident's question used to sit in the bot's list, unseen by the chat, until an admin
 * approved it or fifteen ballots promoted it — so on 28.10.2026 a resident who had asked
 * about walking dogs in the yard saw nothing in the group and posted the question there by
 * hand. What stays gated is the DM to every voter, the part that rings every phone.
 */
class VoteQuestionInChatTest extends KernelTestCase
{
    private function service(): BlockVoteService
    {
        self::bootKernel();

        return self::getContainer()->get(BlockVoteService::class);
    }

    private function question(?TelegramUser $author): BlockVoteCampaign
    {
        return (new BlockVoteCampaign())
            ->setKind(BlockVoteCampaign::KIND_QUESTION)
            ->setQuestion('Чи підтримуєте ви заборону вигулу тварин на території ЖК?')
            ->setAuthor($author);
    }

    public function testAQuietQuestionIsStillPostedInTheChat(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../src/Service/BlockVoteService.php');

        $this->assertMatchesRegularExpression(
            '/if \(\$broadcast\) \{\s*\$this->broadcast\(\$campaign\);\s*\} else \{\s*\$this->announce\(\$campaign\);/',
            $source,
            'openQuestion() must post to the chat even when the DM broadcast is held back',
        );
    }

    public function testAResidentQuestionNamesTheFlatThatAskedIt(): void
    {
        $account = (new Account())
            ->setAccountNumber('3-1-0-029')
            ->setApartmentNumber('29')
            ->setHouseNumber('21')
            ->setStreet('Козацька');
        $author = (new TelegramUser())->setAccount($account);

        $line = $this->service()->askedByLine($this->question($author));

        $this->assertStringContainsString('👤 Запропонували: буд. 21, кв. 29', $line);
    }

    /** A question the ОСББ put from the panel has no resident behind it, and no line. */
    public function testAPanelQuestionHasNoAuthorLine(): void
    {
        $this->assertSame('', $this->service()->askedByLine($this->question(null)));
    }

    public function testTheChatPostCarriesTheLine(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../src/Service/BlockVoteService.php');

        $this->assertStringContainsString('$this->askedByLine($campaign),', $source);
    }
}
