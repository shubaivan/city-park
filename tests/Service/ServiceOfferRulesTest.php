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
     * names two households: a reader cannot tell whose neighbour vouched for this, and a
     * poster receiving «Цікавляться (кв. 45)» cannot tell who wrote. The rental board
     * learned this on 03.09.2026; this one must not have to learn it again.
     */
    public function testEveryLabelNamesTheBuilding(): void
    {
        $service = $this->service();
        $account = $this->account('2-1-0-076', '76');
        $offer = $this->offer($account, 'Плиточник');

        foreach (['card' => $service->describe($offer), 'chat post' => $service->chatPost($offer)] as $what => $text) {
            $this->assertStringContainsString('буд. 19', $text, $what . ' must name the building');
        }

        $this->assertStringContainsString('буд. 19', ServiceOfferService::place($account));
    }

    /**
     * The flat is labelled «Розмістив», never left bare under the trade.
     *
     * The board takes anybody's number — «я хочу розмістити телефон свого друга електрика»
     * — so a bare «буд. 19, кв. 85» under «Електрик» says the electrician lives there,
     * which is then false. Labelled, the same line says who vouches for the card, which is
     * the whole difference between this and a number off a lamppost.
     */
    public function testTheFlatSaysWhoPostedItNotWhoDoesTheWork(): void
    {
        $service = $this->service();
        $offer = $this->offer($this->account('2-1-0-076', '76'), 'Електрик');

        foreach (['card' => $service->describe($offer), 'chat post' => $service->chatPost($offer)] as $what => $text) {
            $this->assertStringContainsString('Розмістив', $text, $what . ' must say whose flat that is');
        }
    }

    /**
     * The chat post carries the number — the one place this board parts company with the
     * rental one, which never prints a phone.
     *
     * That rule is right there and was copied here by reflex. A rental listing is readable
     * by anybody who opens the bot, linked or not, so its post can reach a stranger. The
     * residents' chat is gated by the same list as this board, so the post and the card are
     * read by exactly the same people, and an extra tap between a neighbour and an
     * electrician's number protects nobody.
     */
    public function testTheChatPostCarriesTheNumber(): void
    {
        $offer = $this->offer($this->account('2-1-0-076', '76'), 'Електрик')
            ->setContactPhone('+380 50 313 37 05');

        $this->assertStringContainsString('+380 50 313 37 05', $this->service()->chatPost($offer));
    }

    /** A card with no number is a post with no number, not a post with an empty line. */
    public function testAChatPostWithoutANumberSaysNothingAboutOne(): void
    {
        $post = $this->service()->chatPost($this->offer($this->account('2-1-0-076', '76'), 'Електрик'));

        $this->assertStringNotContainsString('📞', $post);
    }

    /**
     * The button is the trade and nothing else: it is the whole question a reader is
     * scanning for, and the only thing that tells one row from another.
     */
    public function testTheButtonIsTheTrade(): void
    {
        $offer = $this->offer($this->account('2-1-0-076', '76'), 'Плиточник');

        $this->assertSame('Плиточник', $this->service()->buttonLabel($offer));
        $this->assertStringStartsWith('📌 ', $this->service()->buttonLabel($offer, own: true));
    }

    /**
     * No price anywhere. It was asked for on the day the board shipped and came back out
     * the same day: a figure written a month earlier into a classified is a guess or a
     * promise nobody meant to make, and it is settled between the two people once one of
     * them has said what needs doing.
     */
    public function testNothingOnTheCardTalksAboutMoney(): void
    {
        $service = $this->service();
        $offer = $this->offer($this->account('2-1-0-076', '76'), 'Плиточник');

        foreach ([$service->describe($offer), $service->chatPost($offer), $service->buttonLabel($offer)] as $text) {
            $this->assertStringNotContainsString('грн', $text);
            $this->assertStringNotContainsString('💰', $text);
            $this->assertStringNotContainsString('договірна', $text);
        }
    }

    /** The title is a button caption; Telegram truncates, so the entity caps it itself. */
    public function testTheTitleIsCappedAtTheEntity(): void
    {
        $offer = (new ServiceOffer())->setTitle(str_repeat('я', 200));

        $this->assertSame(ServiceOffer::TITLE_MAX, mb_strlen($offer->getTitle(), 'UTF-8'));
    }

    /**
     * A card with no number still works: roughly half of residents have no @username
     * either, and for them the bot relays the enquiry to whoever posted it.
     */
    public function testAnOfferWithNoNumberIsValid(): void
    {
        $offer = $this->offer($this->account('2-1-0-076', '76'), 'Репетитор з англійської');

        $this->assertNull($offer->publicPhone());
        $this->assertStringNotContainsString('📞', $this->service()->describe($offer));
    }

    /**
     * Three per person, and the cap is a constant so it can be moved without hunting.
     *
     * A person really can be an electrician *and* fit kitchens, and «Електрик, ремонт під
     * ключ» crammed into one 60-character button serves neither. Three is where it stops
     * being «I do a few things» and starts being one resident holding the first page —
     * and this board is the only place in the bot where somebody broadcasts to the whole
     * house, so the ceiling is not decoration.
     */
    public function testTheCapIsThreePerPerson(): void
    {
        $this->assertSame(3, ServiceOffer::MAX_PER_AUTHOR);
    }

    /**
     * The cap counts the person, not the flat.
     *
     * Father recommends his electrician and daughter posts her manicurist on the same
     * особовий рахунок; counting per account would make them share three between them.
     */
    public function testTheCapIsCheckedAgainstTheAuthorNotTheAccount(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../src/Service/ServiceOfferService.php');

        $this->assertMatchesRegularExpression(
            '/mayPublishMore\(\?TelegramUser \$author\)/',
            $source,
            'the cap must be asked about a person',
        );
    }

    /**
     * The share block has to survive leaving Telegram.
     *
     * The inline button under the chat post is Telegram's alone: forward that message into
     * Viber and the button is simply not there, leaving a summary that says «кнопка нижче»
     * under nothing. The ЖК's Viber group still holds several hundred residents — it is the
     * group this bot exists to replace and has not replaced yet — so pasting an advert
     * there is the normal case.
     *
     * Plain text, url spelled out, no markup: somebody selects this with a thumb and drops
     * it into another app, and formatting marks would go with it.
     */
    public function testTheShareBlockIsPlainTextWithTheNumber(): void
    {
        $offer = $this->offer($this->account('2-1-0-085', '85'), 'Двері, монтаж')
            ->setContactPhone('+380 68 903 55 91');

        $text = $this->service()->shareText($offer);

        $this->assertStringContainsString('Двері, монтаж', $text);
        $this->assertStringContainsString('+380 68 903 55 91', $text);
        $this->assertStringContainsString('буд. 19', $text, 'a stranger must be able to place the person');

        foreach (['<b>', '</b>', '<i>', '```', '**'] as $markup) {
            $this->assertStringNotContainsString(
                $markup,
                $text,
                'formatting travels with a copy-paste and lands as punctuation',
            );
        }
    }

    private function offer(Account $account, string $title): ServiceOffer
    {
        return (new ServiceOffer())
            ->setAccount($account)
            ->setTitle($title)
            ->setExpiresAt(new \DateTime('+30 days'));
    }
}
