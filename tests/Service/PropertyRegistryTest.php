<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\BlockReasonResolver;
use App\Service\DebtPolicy;
use App\Repository\PhotoUploadRequestRepository;
use App\Repository\TariffRepository;
use App\Service\OwnerGroupService;
use App\Service\PavilionPhotoService;
use App\Service\PropertyRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The register of objects.
 *
 * `/admin/users` answers "хто ця людина"; this answers "що це за об'єкт і хто за ним
 * стоїть". The two rules worth pinning are the ones a later edit will be tempted to
 * simplify away: an object is always named with its building, and an object with no linked
 * resident must still appear — those are precisely the ones whose debt reaches nobody,
 * because every notice the bot sends goes to a TelegramUser.
 */
class PropertyRegistryTest extends TestCase
{
    private function account(int $id, string $number, string $unit, string $debt = '0', ?int $group = null): Account
    {
        $account = (new Account())
            ->setAccountNumber($number)
            ->setApartmentNumber($unit)
            ->setHouseNumber('19')
            ->setStreet('Козацька')
            ->setDebt($debt);
        $account->setOwnerGroupId($group);

        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }

    private function owner(string $first, ?string $role): TelegramUser
    {
        $user = new TelegramUser();
        $user->setFirstName($first);
        $user->setLastName(null);
        $user->setUsername(null);
        $user->setPhoneNumber(null);
        $user->setRole($role);

        return $user;
    }

    /**
     * @param Account[] $accounts
     */
    private function registry(array $accounts): PropertyRegistry
    {
        $repo = $this->createMock(AccountRepository::class);
        $repo->method('findAllWithOwners')->willReturn($accounts);

        // DebtPolicy and BlockReasonResolver are final, so these are the real objects with
        // mocked storage behind them. Accounts here carry no area, which is exactly the
        // case the policy falls back to the global threshold for.
        $debtPolicy = new DebtPolicy(
            1300,
            $repo,
            $this->createMock(TariffRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $blockReasons = new BlockReasonResolver(
            $debtPolicy,
            $this->createMock(PhotoUploadRequestRepository::class),
            $this->createMock(PavilionPhotoService::class),
        );

        return new PropertyRegistry(
            $repo,
            $debtPolicy,
            $blockReasons,
            $this->createMock(OwnerGroupService::class),
        );
    }

    /**
     * The ЖК is five buildings on one street and apartment numbers repeat across them, so
     * an object named by its unit alone names two places at once. Same rule as the debtors'
     * board and the rental noticeboard.
     */
    public function testEveryObjectIsNamedWithItsBuilding(): void
    {
        $registry = $this->registry([]);

        $this->assertSame('буд. 19, кв. 85', $registry->place($this->account(1, '4100085', '85')));
        // Parking and storage carry their own wording, so they must not get a "кв." prefix.
        $this->assertSame('буд. 19, Паркінг 138', $registry->place($this->account(2, '2170138', 'Паркінг 138')));
        $this->assertSame('буд. 19, без номера', $registry->place($this->account(3, '4100000', '')));
    }

    public function testUnitTypeComesFromTheAccountNumberNotTheText(): void
    {
        $registry = $this->registry([]);

        $this->assertSame(PropertyRegistry::TYPE_APARTMENT, $registry->type($this->account(1, '4100085', '85')));
        $this->assertSame(PropertyRegistry::TYPE_PARKING, $registry->type($this->account(2, '317142', 'Паркінг 142')));
        $this->assertSame(PropertyRegistry::TYPE_STORAGE, $registry->type($this->account(3, '315012', 'Комірчина 12')));

        $this->assertSame('🚗 Паркомісце', $registry->typeLabel($this->account(2, '317142', 'Паркінг 142')));
    }

    /**
     * An object with no linked resident is invisible in the register of people — you find
     * an object by finding somebody on it. It must never be invisible here.
     */
    public function testObjectsWithNoOwnerAreListedAndCounted(): void
    {
        $orphan = $this->account(1, '4100085', '85', '2500');
        $owned = $this->account(2, '4100086', '86');
        $owned->getUsers()->add($this->owner('Іван', 'owner'));

        $registry = $this->registry([$orphan, $owned]);
        $rows = $registry->overview();

        $this->assertCount(2, $rows);
        $this->assertSame([], $rows[0]['owners']);
        $this->assertCount(1, $rows[1]['owners']);

        $stats = $registry->stats($rows);
        $this->assertSame(1, $stats['unowned']);
        $this->assertSame(0, $stats['multi_owner']);
        $this->assertSame(1, $stats['in_debt']);
        $this->assertSame(2500.0, $stats['debt']);
    }

    /** The person the ОСББ deals with should not be third in a list of four. */
    public function testOwnersAreOrderedOwnerFamilyTenantUnknown(): void
    {
        $account = $this->account(1, '4100085', '85');
        $account->getUsers()->add($this->owner('Орендар', 'tenant'));
        $account->getUsers()->add($this->owner('Ніхто', null));
        $account->getUsers()->add($this->owner('Власник', 'owner'));
        $account->getUsers()->add($this->owner('Сім’я', 'family'));

        $rows = $this->registry([$account])->overview();

        $this->assertSame(
            ['Власник', 'Сім’я', 'Орендар', 'Ніхто'],
            array_map(static fn (TelegramUser $u): string => (string)$u->getFirstName(), $rows[0]['owners']),
        );
    }

    /**
     * Group members are resolved from the set already in memory: 172 objects would
     * otherwise be 172 extra queries for a column that is empty on almost all of them.
     */
    public function testGroupMembersAndTheirCombinedDebt(): void
    {
        $flat = $this->account(85, '4100085', '85', '1200', 85);
        $parking = $this->account(138, '2170138', 'Паркінг 138', '300', 85);
        $alone = $this->account(200, '4100200', '200', '50');

        $rows = $this->registry([$flat, $parking, $alone])->overview();

        $this->assertCount(1, $rows[0]['siblings']);
        $this->assertSame(138, $rows[0]['siblings'][0]->getId());
        $this->assertSame(1500.0, $rows[0]['group_debt']);

        // Never itself, and an ungrouped object is a group of one with no siblings.
        $this->assertSame([], $rows[2]['siblings']);
        $this->assertSame(50.0, $rows[2]['group_debt']);

        $this->assertSame(2, $this->registry([$flat, $parking, $alone])->stats($rows)['grouped']);
    }

    /**
     * The building chips are built from the data, not from a hardcoded 17/19/21/23/27 —
     * that list silently drops a sixth building the day one appears, and shows an empty
     * one after a renumbering.
     */
    public function testBuildingsComeFromTheDataInWalkingOrder(): void
    {
        $rows = [];

        foreach ([['19', '85'], ['9', '3'], ['17', '1'], ['19', '86'], ['21', '7']] as $i => [$house, $unit]) {
            $account = $this->account($i + 1, '4100' . $unit, $unit);
            $account->setHouseNumber($house);
            $rows[] = ['account' => $account];
        }

        $this->assertSame(
            [
                ['house' => '9', 'count' => 1],
                ['house' => '17', 'count' => 1],
                ['house' => '19', 'count' => 2],
                ['house' => '21', 'count' => 1],
            ],
            // strnatcmp, so "9" sorts before "17" rather than after it.
            $this->registry([])->houses($rows),
        );

        $this->assertSame([], $this->registry([])->houses([]));
    }

    /**
     * Several people on one object — family, tenants, conditional owners. Worth counting:
     * this is where a tenant sits next to an owner and where `role` is most likely wrong or
     * missing, and it is the set somebody actually wants to review.
     */
    public function testObjectsWithSeveralOwnersAreCounted(): void
    {
        $alone = $this->account(1, '4100085', '85');
        $alone->getUsers()->add($this->owner('Іван', 'owner'));

        $pair = $this->account(2, '4100086', '86');
        $pair->getUsers()->add($this->owner('Іван', 'owner'));
        $pair->getUsers()->add($this->owner('Олена', 'family'));

        $crowd = $this->account(3, '4100087', '87');
        $crowd->getUsers()->add($this->owner('Іван', 'owner'));
        $crowd->getUsers()->add($this->owner('Олена', 'family'));
        $crowd->getUsers()->add($this->owner('Орендар', 'tenant'));

        $registry = $this->registry([$alone, $pair, $crowd]);
        $stats = $registry->stats($registry->overview());

        $this->assertSame(2, $stats['multi_owner'], 'two objects have 2 or more owners');
        $this->assertSame(1, $stats['many_owner'], 'one of them has 3');
        $this->assertSame(0, $stats['unowned']);
    }
}
