<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\CarPlate;
use App\Entity\TelegramUser;
use App\Repository\AccountRepository;
use App\Repository\ExpectedResidentRepository;
use App\Service\OwnerGroupService;
use App\Service\ParkingClaimService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The one link to an object the bot makes by itself, and the rules that keep it narrow:
 * only the building the reader picked, only a space nobody else holds, and a taken one
 * answered with whose it is — the flat, never a phone.
 */
class ParkingClaimRulesTest extends TestCase
{
    private function account(int $id, string $number, string $unit, string $house): Account
    {
        $account = (new Account())
            ->setAccountNumber($number)
            ->setApartmentNumber($unit)
            ->setHouseNumber($house)
            ->setStreet('Козацька');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }

    /** @param Account[] $all */
    private function service(array $all, ?OwnerGroupService $groups = null, int $expected = 0): ParkingClaimService
    {
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('findAll')->willReturn($all);

        $expectedRepo = $this->createMock(ExpectedResidentRepository::class);
        $expectedRepo->method('count')->willReturn($expected);

        if ($groups === null) {
            $groups = $this->createMock(OwnerGroupService::class);
            $groups->method('siblings')->willReturn([]);
        }

        return new ParkingClaimService($accounts, $expectedRepo, $groups, $this->createMock(EntityManagerInterface::class), new NullLogger());
    }

    private function plate(Account $on, string $spot): CarPlate
    {
        return (new CarPlate())->setAccount($on)->setPlate('CA1234AB')->setParkingSpot($spot);
    }

    /** «142» is three spaces in three buildings; every one is offered, the number decides nothing. */
    public function testEverySpaceWithTheNumberIsOfferedWhateverItsLabel(): void
    {
        $flat = $this->account(1, '210024', '24', '19');
        $all = [
            $flat,
            $this->account(2, '317142', 'Паркінг 142', '21'),
            $this->account(3, '117142', '142', '17'),
            $this->account(4, '227142', '142', '19'),
            $this->account(5, '227143', '143', '19'),
        ];

        $found = $this->service($all)->candidates($this->plate($flat, "Місце 142, 4 під'їзд, власне"));

        $this->assertSame(['117142', '227142', '317142'], array_map(static fn (Account $a): string => $a->getAccountNumber(), $found));
    }

    public function testAFreeSpaceIsJoinedToTheHousehold(): void
    {
        $flat = $this->account(1, '320091', '91', '21');
        $space = $this->account(2, '327212', '212', '21');

        $groups = $this->createMock(OwnerGroupService::class);
        $groups->method('siblings')->willReturn([]);
        $groups->expects($this->once())->method('link')->with($flat, $space)->willReturn(null);

        $plate = $this->plate($flat, '212 власне');
        [$result] = $this->service([$flat, $space], $groups)->claim($plate, $flat, $space);

        $this->assertSame(ParkingClaimService::LINKED, $result);
        $this->assertTrue($plate->isParkingLinked());
        $this->assertTrue($plate->isSpotOwned());
    }

    /** Somebody in the bot is on it: no join, and the answer names their flat. */
    public function testATakenSpaceIsNotJoinedAndSaysWhoseItIs(): void
    {
        $flat = $this->account(1, '210024', '24', '19');
        $space = $this->account(2, '317142', 'Паркінг 142', '21');
        $neighbourFlat = $this->account(3, '320050', '50', '21');

        $groups = $this->createMock(OwnerGroupService::class);
        $groups->method('siblings')->willReturnCallback(static fn (Account $a): array => $a === $space ? [$neighbourFlat] : []);
        $groups->expects($this->never())->method('link');
        $space->setOwnerGroupId(2);

        $plate = $this->plate($flat, '142');
        [$result, $holder] = $this->service([$flat, $space], $groups)->claim($plate, $flat, $space);

        $this->assertSame(ParkingClaimService::TAKEN, $result);
        $this->assertSame('буд. 21, кв. 50', $holder);
        $this->assertFalse($plate->isParkingLinked());
        $this->assertSame($space, $plate->getParkingAccount(), 'the claim is kept for the panel');
    }

    public function testAResidentLinkedStraightToTheSpaceAlsoHoldsIt(): void
    {
        $flat = $this->account(1, '210024', '24', '19');
        $space = $this->account(2, '227142', '142', '19');
        $space->getUsers()->add((new TelegramUser())->setAccount($space));

        $groups = $this->createMock(OwnerGroupService::class);
        $groups->method('siblings')->willReturn([]);
        $groups->expects($this->never())->method('link');

        [$result] = $this->service([$flat, $space], $groups)->claim($this->plate($flat, '142'), $flat, $space);

        $this->assertSame(ParkingClaimService::TAKEN, $result);
    }

    public function testSomebodyExpectedOnTheSpaceHoldsItToo(): void
    {
        $flat = $this->account(1, '210024', '24', '19');
        $space = $this->account(2, '227142', '142', '19');

        $groups = $this->createMock(OwnerGroupService::class);
        $groups->method('siblings')->willReturn([]);
        $groups->expects($this->never())->method('link');

        [$result] = $this->service([$flat, $space], $groups, 1)->claim($this->plate($flat, '142'), $flat, $space);

        $this->assertSame(ParkingClaimService::TAKEN, $result);
    }

    public function testASpaceWithoutANumberMatchesNothing(): void
    {
        $flat = $this->account(1, '210024', '24', '19');

        $this->assertSame([], $this->service([$flat, $this->account(2, '227142', '142', '19')])->candidates($this->plate($flat, 'біля ліфта')));
    }
}
