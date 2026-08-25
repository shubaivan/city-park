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

    /**
     * The number is opt-in, and half the house has no @username — so the formatter has to
     * cope with whatever shape the registry import and Telegram left behind, and refuse
     * anything it can't turn into a dialable number rather than print a stub.
     */
    public function testPhoneFormatting(): void
    {
        $this->assertSame('+380 93 658 32 02', RentalListingService::formatPhone('+380936583202'));
        $this->assertSame('+380 98 875 54 69', RentalListingService::formatPhone('380988755469'));
        $this->assertSame('+380 63 791 70 11', RentalListingService::formatPhone('0637917011'));
        $this->assertSame('+380 63 791 70 11', RentalListingService::formatPhone('+38 (063) 791-70-11'));

        $this->assertNull(RentalListingService::formatPhone(null));
        $this->assertNull(RentalListingService::formatPhone(''));
        $this->assertNull(RentalListingService::formatPhone('12345'), 'too short to dial');
        $this->assertNull(RentalListingService::formatPhone('491701234567'), 'not a Ukrainian number');
    }

    /**
     * A listing published before the opt-in step — and one whose owner declined — must
     * never render a number. show_phone defaults to false precisely so the migration
     * cannot retroactively publish phones nobody agreed to share.
     */
    public function testPhoneStaysPrivateUnlessOptedIn(): void
    {
        $listing = (new RentalListing())->setContactPhone('+380 63 791 70 11');

        $this->assertFalse($listing->isShowPhone(), 'private by default');
        $this->assertNull($listing->publicPhone());

        $listing->setShowPhone(true);
        $this->assertSame('+380 63 791 70 11', $listing->publicPhone());
    }

    /**
     * The index is buttons, not a wall of descriptions, so this caption is all a reader
     * has to go on when choosing which card to open — apartment, rooms, price, nothing
     * that Telegram will truncate.
     */
    public function testIndexButtonLabel(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(RentalListingService::class);

        $listing = (new RentalListing())
            ->setAccount($this->account('1-1-0-085', '85'))
            ->setRooms(1)
            ->setPrice(20000);

        $this->assertSame('кв. 85 · 1-кімн. · 20 000 грн/міс', $service->buttonLabel($listing));
        $this->assertSame('📌 кв. 85 · 1-кімн. · 20 000 грн/міс', $service->buttonLabel($listing, own: true));
        $this->assertLessThan(64, mb_strlen($service->buttonLabel($listing, own: true)));

        $open = (new RentalListing())
            ->setAccount($this->account('1-1-0-012', '12'))
            ->setPrice(null);

        $this->assertSame('кв. 12 · ціна договірна', $service->buttonLabel($open), 'rooms may be absent');
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
