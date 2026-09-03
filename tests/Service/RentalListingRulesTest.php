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
     * has to go on when choosing which card to open — building, apartment, rooms, price,
     * nothing that Telegram will truncate.
     *
     * The building is not optional. Apartment numbers repeat across the ЖК's five
     * buildings, so «кв. 85» names two different flats and a reader cannot tell which one
     * to walk to. Same rule as DebtBoardService::place(); it reached the rental listings
     * on 03.09.2026, having been missed when they shipped. The short «б.» form is used
     * because Telegram truncates long button captions.
     */
    public function testIndexButtonLabel(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(RentalListingService::class);

        $listing = (new RentalListing())
            ->setAccount($this->account('1-1-0-085', '85'))
            ->setRooms(1)
            ->setPrice(20000);

        $this->assertSame('б. 1, кв. 85 · 1-кімн. · 20 000 грн/міс', $service->buttonLabel($listing));
        $this->assertSame('📌 б. 1, кв. 85 · 1-кімн. · 20 000 грн/міс', $service->buttonLabel($listing, own: true));
        $this->assertLessThan(64, mb_strlen($service->buttonLabel($listing, own: true)));

        $open = (new RentalListing())
            ->setAccount($this->account('1-1-0-012', '12'))
            ->setPrice(null);

        $this->assertSame('б. 1, кв. 12 · ціна договірна', $service->buttonLabel($open), 'rooms may be absent');
    }

    /**
     * Every label a resident or an owner reads must name the building — the listing card,
     * the index button, the contact button and the relay line that tells an owner who is
     * asking. This is the regression guard for all four at once.
     */
    public function testEveryRentalLabelNamesTheBuilding(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(RentalListingService::class);

        $listing = (new RentalListing())
            ->setAccount($this->account('1-1-0-085', '85'))
            ->setRooms(1)
            ->setPrice(20000);
        // describe() prints the publication and expiry dates, which Doctrine would have
        // stamped on persist — this listing never reaches the database.
        $listing->setCreatedAt(new \DateTime());
        $listing->setExpiresAt((new \DateTime())->modify('+30 days'));

        foreach ([
            'картка' => $service->describe($listing),
            'кнопка списку' => $service->buttonLabel($listing),
            'кнопка контакту' => $service->contactButton($listing)->text,
        ] as $what => $label) {
            $this->assertMatchesRegularExpression(
                '/б(уд)?\. 1, кв\. 85/u',
                $label,
                sprintf('%s must name the building: apartment numbers repeat across the five buildings', $what),
            );
        }

        $this->assertSame('буд. 1, кв. 85', RentalListingService::place($this->account('1-1-0-085', '85')));
        $this->assertSame('б. 1, кв. 85', RentalListingService::placePlain($this->account('1-1-0-085', '85'), true));
        $this->assertSame('', RentalListingService::place(null), 'an unlinked reader has no place to name');
    }

    /**
     * Photos are optional and capped. The cap is enforced on the entity rather than only
     * in the upload endpoint, so a listing can never render a card with more pictures
     * than the card was designed for.
     */
    public function testPhotosAreOptionalAndCapped(): void
    {
        $listing = new RentalListing();

        $this->assertFalse($listing->hasPhotos(), 'a listing without photos is normal');
        $this->assertNull($listing->coverPhoto());
        $this->assertSame([], $listing->getPhotos());

        $listing->setPhotos([
            '/uploads/rental-photos/2026/08/a.jpg',
            '/uploads/rental-photos/2026/08/b.jpg',
            '/uploads/rental-photos/2026/08/c.jpg',
            '/uploads/rental-photos/2026/08/d.jpg',
        ]);

        $this->assertCount(RentalListing::PHOTOS_MAX, $listing->getPhotos());
        $this->assertSame('/uploads/rental-photos/2026/08/a.jpg', $listing->coverPhoto());
    }

    /**
     * The upload link is the only authorisation on the photo page, so an expired or
     * missing one must never resolve.
     */
    public function testPhotoTokenValidity(): void
    {
        $now = new \DateTime('2026-08-26 12:00:00');
        $listing = new RentalListing();

        $this->assertFalse($listing->isPhotoTokenValid($now), 'no token issued yet');

        $listing->setPhotoToken(str_repeat('a', 32));
        $listing->setPhotoTokenExpiresAt(new \DateTime('2026-08-26 11:59:00'));
        $this->assertFalse($listing->isPhotoTokenValid($now), 'expired a minute ago');

        $listing->setPhotoTokenExpiresAt(new \DateTime('2026-08-27 11:00:00'));
        $this->assertTrue($listing->isPhotoTokenValid($now));
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
