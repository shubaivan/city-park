<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Service\OwnerGroupService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * "One owner, several objects".
 *
 * Each property is its own Account — a flat, a parking space and a storage room are three
 * особові рахунки — and `owner_group_id` is the admin's statement that some of those rows
 * are one household. The bot then counts booking limits across the group and lets a debt on
 * any object block all of them, so getting the merge rules wrong silently changes who may
 * book and who is blocked.
 */
class OwnerGroupRulesTest extends TestCase
{
    /** @var Account[] */
    private array $accounts = [];

    private function account(int $id, ?int $groupId = null): Account
    {
        $account = (new Account())
            ->setAccountNumber('41000' . $id)
            ->setApartmentNumber((string)$id)
            ->setHouseNumber('19')
            ->setStreet('Козацька');
        $account->setOwnerGroupId($groupId);

        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);
        $this->accounts[$id] = $account;

        return $account;
    }

    private function service(): OwnerGroupService
    {
        $repo = $this->createMock(AccountRepository::class);

        $repo->method('findBy')->willReturnCallback(
            fn (array $criteria) => array_values(array_filter(
                $this->accounts,
                static fn (Account $a): bool => $a->getOwnerGroupId() === ($criteria['owner_group_id'] ?? null),
            )),
        );

        $repo->method('findGroupSiblings')->willReturnCallback(
            fn (Account $account) => $account->getOwnerGroupId() === null
                ? [$account]
                : array_values(array_filter(
                    $this->accounts,
                    static fn (Account $a): bool => $a->getOwnerGroupId() === $account->getOwnerGroupId(),
                )),
        );

        return new OwnerGroupService($repo, $this->createMock(EntityManagerInterface::class), new NullLogger());
    }

    public function testTwoUngroupedObjectsBecomeAGroupWithADeterministicId(): void
    {
        $flat = $this->account(85);
        $parking = $this->account(138);

        $this->assertNull($this->service()->link($flat, $parking));

        // The smaller id survives, so the same merge done twice from two different screens
        // lands on the same answer.
        $this->assertSame(85, $flat->getOwnerGroupId());
        $this->assertSame(85, $parking->getOwnerGroupId());
    }

    public function testAnObjectJoinsAnExistingGroupRatherThanStartingANewOne(): void
    {
        $flat = $this->account(85, 85);
        $parking = $this->account(138, 85);
        $storage = $this->account(200);

        $this->assertNull($this->service()->link($storage, $flat));

        $this->assertSame(85, $storage->getOwnerGroupId());
        $this->assertSame(85, $parking->getOwnerGroupId(), 'the existing members must not move');
    }

    /** Two groups merging keep the smaller id, and every member of the larger one follows. */
    public function testMergingTwoGroupsMovesEveryMember(): void
    {
        $a1 = $this->account(10, 10);
        $a2 = $this->account(11, 10);
        $b1 = $this->account(50, 50);
        $b2 = $this->account(51, 50);

        $this->assertNull($this->service()->link($b1, $a1));

        foreach ([$a1, $a2, $b1, $b2] as $account) {
            $this->assertSame(10, $account->getOwnerGroupId());
        }
    }

    public function testSelfLinkAndDoubleLinkAreRefusedWithAMessage(): void
    {
        $flat = $this->account(85, 85);
        $parking = $this->account(138, 85);

        $this->assertNotNull($this->service()->link($flat, $flat));
        $this->assertNotNull($this->service()->link($flat, $parking));
    }

    /**
     * A group of one is meaningless — getEffectiveGroupId() treats it exactly like an
     * ungrouped account — so the last member left behind is cleared too. Otherwise the
     * panel shows somebody as "grouped" with nobody.
     */
    public function testUnlinkingTheSecondOfTwoDissolvesTheGroup(): void
    {
        $flat = $this->account(85, 85);
        $parking = $this->account(138, 85);

        $this->assertNull($this->service()->unlink($parking));

        $this->assertNull($parking->getOwnerGroupId());
        $this->assertNull($flat->getOwnerGroupId(), 'a group of one must not survive');
    }

    public function testUnlinkingFromAGroupOfThreeLeavesTheOthersGrouped(): void
    {
        $a = $this->account(10, 10);
        $b = $this->account(11, 10);
        $c = $this->account(12, 10);

        $this->assertNull($this->service()->unlink($c));

        $this->assertNull($c->getOwnerGroupId());
        $this->assertSame(10, $a->getOwnerGroupId());
        $this->assertSame(10, $b->getOwnerGroupId());
    }

    public function testUnlinkingAnUngroupedObjectSaysSoRatherThanPretending(): void
    {
        $this->assertNotNull($this->service()->unlink($this->account(85)));
    }

    public function testSiblingsNeverIncludeTheAccountItself(): void
    {
        $flat = $this->account(85, 85);
        $this->account(138, 85);

        $siblings = $this->service()->siblings($flat);

        $this->assertCount(1, $siblings);
        $this->assertSame(138, $siblings[0]->getId());
        $this->assertSame([], $this->service()->siblings($this->account(200)));
    }
}
