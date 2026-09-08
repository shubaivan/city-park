<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\ServiceOffer;
use App\Service\ServiceOfferService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * **Every list of things people publish is a list of buttons, and every button leads with
 * the date the thing was published.**
 *
 * `08.09 · Двері, монтаж 📷 📌` on the services board, `🆕 08.09 · Ліфт не працює 📷 📌` in
 * the complaints register. These lists are read for freshness as much as for content —
 * «Електрик 08.09» and «Електрик 12.08» are different offers to somebody deciding who to
 * ring, and «ліфт не працює» from yesterday is a different thing from the same words from
 * March.
 *
 * The date goes **first**, at a fixed width, so the dates line up down the column and the
 * list can be scanned in one movement; a trailing date cannot do that because the titles
 * are ragged. Badges go **last**: they qualify a row rather than identify it, and Telegram
 * truncates long captions from the right, so the two things that must survive — when, and
 * what — sit where they cannot be cut.
 *
 * The rental board is the deliberate exception: its buttons carry the flat, the rooms and
 * the price, and a listing is chosen on those rather than on freshness.
 */
class ListButtonShapeTest extends KernelTestCase
{
    public function testTheServicesButtonLeadsWithTheDate(): void
    {
        self::bootKernel();

        $account = (new Account())
            ->setAccountNumber('2-1-0-076')
            ->setApartmentNumber('76')
            ->setHouseNumber('19')
            ->setStreet('Козацька');

        $offer = (new ServiceOffer())
            ->setAccount($account)
            ->setTitle('Двері, монтаж')
            ->setPhotos(['/a.jpg'])
            ->setExpiresAt(new \DateTime('+30 days'));
        $offer->setCreatedAt(new \DateTime('2026-09-08'));

        $label = self::getContainer()->get(ServiceOfferService::class)->buttonLabel($offer, own: true);

        $this->assertSame('08.09 · Двері, монтаж 📷 📌', $label);
    }

    /**
     * The complaints register is where this shape came from; it must not drift away from
     * it while the services board follows it.
     */
    public function testTheComplaintsButtonHasTheSameShape(): void
    {
        $source = (string)file_get_contents(
            __DIR__ . '/../../src/Telegram/Complaint/Command/ComplaintMenuCommand.php'
        );

        $start = strpos($source, 'private function listLabel(');
        $body = substr($source, (int)$start, 900);

        $this->assertStringContainsString(
            "format('d.m')",
            $body,
            'the complaints list button lost its date',
        );

        $this->assertLessThan(
            strpos($body, 'service->label('),
            strpos($body, "format('d.m')"),
            'the date must come before the text, so the column lines up',
        );
    }
}
