<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\BlockVoteBallot;
use App\Entity\TelegramUser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The roll call under a campaign on /admin/block-votes.
 *
 * A ballot is the one place in this panel where a resident is read as a name — «кв. 47 ·
 * Юлія» — and the name went nowhere, so the question it raises («хто це?») was answered by
 * opening /admin/users in another tab and searching. Every other name here is a link to the
 * card that answers it.
 */
class AdminBlockVotesPageTest extends KernelTestCase
{
    private function render(array $ballots): string
    {
        self::bootKernel();

        return self::getContainer()->get(Environment::class)->createTemplate(
            '{% import "admin/block-votes.html.twig" as box %}{{ box.ballots(list, true) }}'
        )->render(['list' => $ballots]);
    }

    private function ballot(bool $value, int $accountId, string $flat, ?int $userId): BlockVoteBallot
    {
        $account = (new Account())
            ->setAccountNumber('2200' . $flat)
            ->setApartmentNumber($flat)
            ->setHouseNumber('19')
            ->setStreet('Козацька');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $accountId);

        $ballot = (new BlockVoteBallot())->setVoterAccount($account)->setValue($value);
        $ballot->setCastAt(new \DateTime('2026-09-09 16:23:00'));

        if ($userId !== null) {
            $user = new TelegramUser();
            $user->setTelegramId('900' . $userId);
            $user->setFirstName('Юлія');
            (new \ReflectionProperty(TelegramUser::class, 'id'))->setValue($user, $userId);
            $ballot->setVoterUser($user);
        }

        return $ballot;
    }

    public function testAVoterOpensTheirOwnCard(): void
    {
        $html = $this->render([$this->ballot(false, 47, '47', 12)]);

        $this->assertStringContainsString('/admin/users/12', $html, 'the name must lead to the resident');
        $this->assertStringContainsString('Юлія', $html);
        $this->assertStringContainsString('кв. 47', $html);
    }

    /**
     * `voter_user` is SET NULL, so a ballot outlives the resident being unlinked or
     * removed — and then the flat is all it has. It still leads somewhere: the object.
     */
    public function testABallotWithNoResidentLeftLeadsToTheObject(): void
    {
        $html = $this->render([$this->ballot(true, 85, '85', null)]);

        $this->assertStringContainsString('/admin/objects/85', $html);
        $this->assertStringNotContainsString('/admin/users/', $html);
    }
}
