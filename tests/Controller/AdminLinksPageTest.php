<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\LinkClick;
use App\Entity\RentalListing;
use App\Repository\LinkClickRepository;
use App\Repository\RentalListingRepository;
use App\Service\DeepLink;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The click log links to what was clicked — and only where the reader may actually go.
 *
 * `/admin/links` is open to both roles, but `/admin/services` and `/admin/rentals` are the
 * accountant's alone. A link that renders and then answers 403 is reported as «панель не
 * працює», which is the same reason the resident card hides every form the complaints role
 * cannot submit.
 */
class AdminLinksPageTest extends WebTestCase
{
    private function loginAs(string $login): KernelBrowser
    {
        $client = static::createClient();
        $provider = static::getContainer()->get('security.user.provider.concrete.users_in_memory');

        $client->loginUser($provider->loadUserByIdentifier($login));

        return $client;
    }

    /** @return array<string, array{string}> */
    public static function roles(): array
    {
        return [
            'the accountant' => ['alina'],
            'the complaints role' => ['serhii'],
        ];
    }

    /** @dataProvider roles */
    public function testTheLogIsReadableAndChangesNothing(string $login): void
    {
        $client = $this->loginAs($login);
        $client->request('GET', '/admin/links');

        $this->assertNotSame(403, $client->getResponse()->getStatusCode(), '/admin/links must stay readable');
        $this->assertNotSame(302, $client->getResponse()->getStatusCode(), 'the client is not signed in');
    }

    /** Nothing on that page is anybody's to change. */
    public function testItRefusesAnythingButReading(): void
    {
        $client = $this->loginAs('alina');
        $client->request('POST', '/admin/links');

        $this->assertSame(405, $client->getResponse()->getStatusCode());
    }

    /**
     * The rule the template has to keep: everything but the complaints register is linked
     * to only when the reader is an admin.
     *
     * One gate, in one macro. It used to be written out twice — once for each table — and
     * the day a third table is added it would have been written a third time or forgotten.
     */
    public function testTheTemplateGuardsTheAdminOnlyLinks(): void
    {
        $template = $this->template();

        foreach (['app_admin_services', 'app_admin_rentals', 'app_admin_block_votes', 'app_admin_debt'] as $route) {
            $this->assertStringContainsString(
                $route,
                $template,
                $route . ': the log should link to what was clicked',
            );
        }

        $this->assertSame(
            1,
            substr_count($template, "is_granted('ROLE_ADMIN')"),
            'the gate belongs in the one macro that builds a link, not once per table',
        );

        $this->assertMatchesRegularExpression(
            "/kind == 'complaint'.*?is_granted\('ROLE_ADMIN'\)/s",
            $template,
            'the complaints register is the one page both roles may open, so it is answered before the gate',
        );
    }

    /**
     * The name opens the resident's card for everyone, the unlinked included.
     *
     * The flat beside it links to the same card, but only for somebody who has a flat — so
     * the people nobody recognises, the ones the question «хто це?» is really about, had no
     * way through at all.
     */
    public function testTheNameOpensTheResidentCardEvenWithoutAFlat(): void
    {
        $this->assertMatchesRegularExpression(
            "/\{% if click\.user %\}(?:(?!click\.user\.account).)*?path\('app_admin_resident'/s",
            $this->template(),
            'the name must link to the card before, and regardless of, the flat check',
        );
    }

    /**
     * Every kind of link the bot can mint must be named by this page.
     *
     * Both tables used to end in a bare `else` that meant "complaint", so the Face ID vote
     * — 56 clicks across two posts — was drawn as «🔧 заявка #4 (видалено)» and linked to
     * the rental register. Nothing failed; the page simply said something untrue about the
     * busiest post in the house, and it stayed untrue until somebody followed the link.
     *
     * The guard's QR is deliberately excluded: it is signed, carries no id, and is never
     * recorded as a click.
     */
    public function testEveryRecordedKindIsNamedByThePage(): void
    {
        $template = $this->template();

        // The two QR kinds are excluded on both sides: `DeepLink::record()` refuses them,
        // so no click of theirs can ever reach this page. Their own log is /admin/scans.
        $kinds = array_diff(array_keys(DeepLink::PREFIXES), DeepLink::UNRECORDED);
        $this->assertNotEmpty($kinds);

        foreach ($kinds as $kind) {
            $this->assertStringContainsString(
                "kind == '" . $kind . "'",
                $template,
                sprintf(
                    'the page has no word for a «%s» link, so it will render as whatever the '
                        . 'last branch happens to be',
                    $kind,
                ),
            );
        }
    }

    /**
     * A board nobody has opened must still have a row saying so.
     *
     * The per-post table only lists posts that were clicked, so an ignored board has no
     * row — and no row reads as "no such thing" rather than as nought, which is the one
     * number the page exists to produce.
     */
    public function testTheTotalsCoverEveryBoardIncludingTheQuietOnes(): void
    {
        $controller = (string)file_get_contents(__DIR__ . '/../../src/Controller/AdminController.php');

        preg_match('/private static function clicksByKind\(.*?\n    \}/s', $controller, $match);
        $this->assertNotEmpty($match, 'clicksByKind() is what fills the per-board table');

        foreach (array_diff(array_keys(DeepLink::PREFIXES), DeepLink::UNRECORDED) as $kind) {
            $this->assertStringContainsString(
                'KIND_' . strtoupper($kind),
                $match[0],
                sprintf('«%s» is missing from the per-board totals', $kind),
            );
        }
    }

    /**
     * The anchors this page links to must exist on the pages it links to, and the row they
     * land on must be marked.
     *
     * A link to `#offer-4` on a page whose rows carry no such id is not an error: the
     * browser opens the page at the top and the reader is left scanning a table of
     * near-identical lines for the one they were sent to — which is exactly what linking
     * straight to the row was for. Renaming an id is a one-word change on the other page
     * and there is nothing else to notice it.
     */
    public function testTheAnchorsItLinksToExistAndAreMarked(): void
    {
        $template = $this->template();

        $pages = [
            'offer-' => 'templates/admin/services.html.twig',
            'listing-' => 'templates/admin/rentals.html.twig',
            'complaint-' => 'templates/admin/complaints.html.twig',
            // The votes page was the last one linked to bare: a click on «голосування #5»
            // opened the register at the top, and with the open and the finished tables
            // both on it, finding #5 was the reader's problem.
            'vote-' => 'templates/admin/block-votes.html.twig',
        ];

        foreach ($pages as $prefix => $page) {
            $this->assertStringContainsString(
                '#' . $prefix,
                $template,
                'the click log builds this anchor',
            );

            $this->assertStringContainsString(
                'id="' . $prefix . '{{',
                (string)file_get_contents(__DIR__ . '/../../' . $page),
                $page . ': nothing on that page carries the id the log links to',
            );
        }

        $this->assertStringContainsString(
            ':target',
            (string)file_get_contents(__DIR__ . '/../../templates/base.html.twig'),
            'the row somebody was sent to has to be marked, or the link is only a scroll position',
        );
    }

    /**
     * The label follows the listing, not the link: #5 was a sale drawn as «🔑 оренда #5».
     *
     * A click that only «Останні переходи» holds still has to find its listing, or it falls
     * back to the word that claims nothing, so the summary here is left empty on purpose.
     */
    public function testARentalLinkIsLabelledByTheDealOfItsListing(): void
    {
        $sale = $this->listing(5, RentalListing::DEAL_SALE);
        $rent = $this->listing(4, RentalListing::DEAL_RENT);

        $html = $this->page([], [
            (new LinkClick())->setKind(DeepLink::KIND_RENTAL)->setTargetId(5),
            (new LinkClick())->setKind(DeepLink::KIND_RENTAL)->setTargetId(4),
            (new LinkClick())->setKind(DeepLink::KIND_RENTAL)->setTargetId(99),
        ], [$sale, $rent]);

        $this->assertMatchesRegularExpression('/🏡\s*продаж #5/u', $html);
        $this->assertMatchesRegularExpression('/🔑\s*оренда #4/u', $html);
        $this->assertMatchesRegularExpression('/📋\s*оголошення #99/u', $html, 'a deleted listing must not claim a deal');
        $this->assertStringNotContainsString('оренда #5', $html);
    }

    /** The per-post table draws the same icon, and the board's total covers both deals. */
    public function testThePerPostTableAndTheBoardTotalAreNotRentOnly(): void
    {
        $sale = $this->listing(5, RentalListing::DEAL_SALE);

        $html = $this->page([[
            'kind' => DeepLink::KIND_RENTAL, 'target_id' => 5, 'clicks' => 16, 'people' => 14, 'last_at' => '2026-11-04 08:34:34',
        ]], [], [$sale]);

        $this->assertMatchesRegularExpression('/🏡\s*(<a[^>]*>)?\s*<b>/u', $html);
        $this->assertStringContainsString('оренда і продаж', $html);
    }

    /** @param list<array<string, mixed>> $summary @param list<LinkClick> $recent @param list<RentalListing> $listings */
    private function page(array $summary, array $recent, array $listings): string
    {
        $client = $this->loginAs('alina');

        $clicks = $this->createMock(LinkClickRepository::class);
        $clicks->method('summary')->willReturn($summary);
        $clicks->method('recent')->willReturn($recent);
        static::getContainer()->set(LinkClickRepository::class, $clicks);

        $repository = $this->createMock(RentalListingRepository::class);
        $repository->method('findBy')->willReturn($listings);
        static::getContainer()->set(RentalListingRepository::class, $repository);

        $client->request('GET', '/admin/links');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        return (string)$client->getResponse()->getContent();
    }

    private function listing(int $id, string $deal): RentalListing
    {
        $listing = (new RentalListing())->setDeal($deal)->setAccount((new Account())->setHouseNumber('19')->setApartmentNumber('62')->setAccountNumber('520062'));
        (new \ReflectionProperty(RentalListing::class, 'id'))->setValue($listing, $id);

        return $listing;
    }

    private function template(): string
    {
        return (string)file_get_contents(__DIR__ . '/../../templates/admin/links.html.twig');
    }
}
