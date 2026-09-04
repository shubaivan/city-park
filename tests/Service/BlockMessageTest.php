<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Repository\PhotoUploadRequestRepository;
use App\Repository\TariffRepository;
use App\Service\BlockReasonResolver;
use App\Service\DebtPolicy;
use App\Service\PavilionPhotoService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The message a blocked resident actually reads.
 *
 * One person can now hold several objects — a flat, a parking space, a комірчина. The
 * neighbouring branch of the booking gate (a debt on *another* object of the same owner)
 * names the object it is talking about; this one did not, so the same person could receive
 * two messages about the same subject, one of which said «поточний борг 3 415.50» and left
 * them to guess which door it belonged to.
 */
class BlockMessageTest extends TestCase
{
    private function resolver(): BlockReasonResolver
    {
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('findGroupSiblings')->willReturnCallback(static fn (Account $a): array => [$a]);

        $tariff = $this->createMock(TariffRepository::class);
        $requests = $this->createMock(PhotoUploadRequestRepository::class);
        $requests->method('findEarliestBlockedOpen')->willReturn(null);

        return new BlockReasonResolver(
            new DebtPolicy(1300, $accounts, $tariff, $this->createMock(EntityManagerInterface::class)),
            $requests,
            $this->createMock(PavilionPhotoService::class),
        );
    }

    private function account(string $number, string $unit, string $debt): Account
    {
        $account = (new Account())
            ->setAccountNumber($number)
            ->setApartmentNumber($unit)
            ->setHouseNumber('19')
            ->setStreet('Козацька')
            ->setDebt($debt);
        $account->setIsActive(false);

        return $account;
    }

    public function testTheBlockMessageNamesTheObjectItIsAbout(): void
    {
        $message = $this->resolver()->botMessage(
            $this->account('230085', '85', '3415.50'),
            new \DateTime(),
        );

        $this->assertStringContainsString('буд. 19, кв. 85', $message);
        $this->assertStringContainsString('рахунок 230085', $message);
        $this->assertStringContainsString('3 415.50', $message);
    }

    /** And it calls a комірчина a комірчина, not a flat. */
    public function testANonFlatObjectIsNamedForWhatItIs(): void
    {
        $message = $this->resolver()->botMessage(
            $this->account('235168', '168', '209.25'),
            new \DateTime(),
        );

        $this->assertStringContainsString('буд. 19, комірчина 168', $message);
        $this->assertStringNotContainsString('кв. 168', $message);
    }

    public function testAnActiveAccountGetsNoMessageAtAll(): void
    {
        $account = $this->account('230085', '85', '0');
        $account->setIsActive(true);

        $this->assertNull($this->resolver()->botMessage($account, new \DateTime()));
    }
}
