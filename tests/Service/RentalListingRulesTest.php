<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\RentalListing;
use App\Service\RentalListingService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Who may advertise an apartment for rent, and how a listing renders.
 *
 * The publishing rule is the load-bearing one: a debt or a missed pavilion photo flips
 * Account::is_active and blocks booking, but it must NOT stop an owner from offering
 * their own property — that decision was made deliberately and is easy to "tidy away"
 * later by someone adding an is_active check for consistency with the rest of the bot.
 */
class RentalListingRulesTest extends KernelTestCase
{
    private function account(string $accountNumber, string $apartment, bool $isActive = true): Account
    {
        $account = (new Account())
            ->setAccountNumber($accountNumber)
            ->setApartmentNumber($apartment)
            ->setHouseNumber('1')
            ->setStreet('Героїв Дніпра');
        $account->setIsActive($isActive);

        return $account;
    }

    public function testApartmentOwnerMayPublish(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(RentalListingService::class);

        $this->assertTrue($service->canPublish($this->account('1-1-0-045', '45')));
    }

    public function testBlockedAccountMayStillPublish(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(RentalListingService::class);

        $blocked = $this->account('1-1-0-045', '45', isActive: false);

        $this->assertTrue(
            $service->canPublish($blocked),
            'a debt/photo block restricts booking, not the right to rent out your own flat',
        );
    }

    public function testParkingAndStorageMayNotPublish(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(RentalListingService::class);

        $this->assertFalse($service->canPublish($this->account('1-1-7-012', 'паркінг 12')));
        $this->assertFalse($service->canPublish($this->account('1-1-5-003', 'кладова 3')));
    }

    public function testLabels(): void
    {
        $listing = (new RentalListing())->setRooms(2)->setPrice(12000);
        $this->assertSame('2-кімн.', $listing->roomsLabel());
        $this->assertSame('12 000 грн/міс', $listing->priceLabel());

        $open = (new RentalListing())->setRooms(5)->setPrice(null);
        $this->assertSame('4+ кімн.', $open->roomsLabel(), 'rooms are bucketed at 4+');
        $this->assertSame('ціна договірна', $open->priceLabel());

        $this->assertNull((new RentalListing())->roomsLabel());
    }
}
