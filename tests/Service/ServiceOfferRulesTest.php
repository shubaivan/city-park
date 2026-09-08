<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\ServiceOffer;
use App\Service\ServiceOfferService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The rules of the trade board that someone will be tempted to "fix" later.
 *
 * Each of them was a deliberate call, and each looks like an inconsistency with the rest
 * of the bot until you know why it is there.
 */
class ServiceOfferRulesTest extends KernelTestCase
{
    private function account(string $accountNumber, string $apartment, bool $isActive = true): Account
    {
        $account = (new Account())
            ->setAccountNumber($accountNumber)
            ->setApartmentNumber($apartment)
            ->setHouseNumber('19')
            ->setStreet('Козацька');
        $account->setIsActive($isActive);

        return $account;
    }

    private function service(): ServiceOfferService
    {
        self::bootKernel();

        return self::getContainer()->get(ServiceOfferService::class);
    }

    public function testAnyConfirmedResidentMayPublish(): void
    {
        $this->assertTrue($this->service()->canPublish($this->account('1-1-0-045', '45')));
    }

    /**
     * A debt or a missed pavilion photo blocks *booking*. Blocking a debtor from
     * advertising the work they do would take away the thing that lets them pay.
     */
    public function testBlockedAccountMayStillPublish(): void
    {
        $this->assertTrue(
            $this->service()->canPublish($this->account('1-1-0-045', '45', isActive: false)),
            'a debt/photo block restricts booking, not the right to offer your own labour',
        );
    }

    /**
     * The one place this board is deliberately wider than the rental one: a rental card is
     * written about a flat, so a комірчина cannot fill one in — but a person whose only
     * object is a parking space can still lay tiles.
     */
    public function testParkingAndStorageOwnersMayPublish(): void
    {
        $service = $this->service();

        $this->assertTrue($service->canPublish($this->account('1-1-7-012', 'паркомісце 12')));
        $this->assertTrue($service->canPublish($this->account('1-1-5-003', 'комірчина 3')));
    }

    /** Publishing needs an Account — it is where the flat on the card comes from. */
    public function testUnlinkedVisitorMayNotPublish(): void
    {
        $this->assertFalse($this->service()->canPublish(null));
    }

    /**
     * The ЖК is five buildings on one street with repeating apartment numbers, so «кв. 76»
     * names two households: a reader cannot tell whose neighbour this is, and an author
     * receiving «Цікавляться (кв. 45)» cannot tell who wrote. The rental board learned
     * this on 03.09.2026; this one must not have to learn it again.
     */
    public function testEveryLabelNamesTheBuilding(): void
    {
        $service = $this->service();
        $account = $this->account('2-1-0-076', '76');

        $offer = (new ServiceOffer())
            ->setAccount($account)
            ->setTitle('Плиточник')
            ->setPriceNote('від 500 грн')
            ->setExpiresAt(new \DateTime('+30 days'));

        foreach (['card' => $service->describe($offer), 'chat post' => $service->chatPost($offer)] as $what => $text) {
            $this->assertStringContainsString('буд. 19', $text, $what . ' must name the building');
        }

        $this->assertStringContainsString('буд. 19', ServiceOfferService::place($account));
    }

    /**
     * Consent was given for the card in the bot, which only confirmed residents open —
     * not for a message the whole house reads and can forward anywhere.
     */
    public function testTheChatPostNeverCarriesAPhone(): void
    {
        $offer = (new ServiceOffer())
            ->setAccount($this->account('2-1-0-076', '76'))
            ->setTitle('Електрик')
            ->setShowPhone(true)
            ->setContactPhone('+380 50 313 37 05')
            ->setExpiresAt(new \DateTime('+30 days'));

        $post = $this->service()->chatPost($offer);

        $this->assertStringNotContainsString('313', $post);
        $this->assertStringNotContainsString('380', $post);
    }

    /**
     * The trade leads the button, because that is the whole question a reader is scanning
     * for — the price only matters once they have found a плиточник at all.
     */
    public function testTheButtonLeadsWithTheTrade(): void
    {
        $offer = (new ServiceOffer())
            ->setAccount($this->account('2-1-0-076', '76'))
            ->setTitle('Плиточник')
            ->setPriceNote('від 500 грн')
            ->setExpiresAt(new \DateTime('+30 days'));

        $label = $this->service()->buttonLabel($offer);

        $this->assertStringStartsWith('Плиточник', $label);
        $this->assertStringContainsString('від 500 грн', $label);
    }

    /** «ціна договірна» is spelled out — a blank reads as missing data, not as "ask me". */
    public function testAMissingPriceIsSpelledOut(): void
    {
        $offer = (new ServiceOffer())->setTitle('Манікюр вдома');

        $this->assertSame('ціна договірна', $offer->priceLabel());
    }

    /** The title is a button caption; Telegram truncates, so the entity caps it itself. */
    public function testTheTitleIsCappedAtTheEntity(): void
    {
        $offer = (new ServiceOffer())->setTitle(str_repeat('я', 200));

        $this->assertSame(ServiceOffer::TITLE_MAX, mb_strlen($offer->getTitle(), 'UTF-8'));
    }

    /**
     * Free text, not a number: a trade has «від 500 грн», «300 грн/год», «за
     * домовленістю». Forcing that into an integer would make every honest answer a lie.
     */
    public function testThePriceIsFreeTextAndBlankMeansNegotiable(): void
    {
        $this->assertNull((new ServiceOffer())->setPriceNote('   ')->getPriceNote());
        $this->assertSame('300 грн/год', (new ServiceOffer())->setPriceNote(' 300 грн/год ')->getPriceNote());
    }
}
