<?php

namespace App\Tests\Controller;

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

        $kinds = array_diff(array_keys(DeepLink::PREFIXES), [DeepLink::KIND_GUARD]);
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

        foreach (array_diff(array_keys(DeepLink::PREFIXES), [DeepLink::KIND_GUARD]) as $kind) {
            $this->assertStringContainsString(
                'KIND_' . strtoupper($kind),
                $match[0],
                sprintf('«%s» is missing from the per-board totals', $kind),
            );
        }
    }

    private function template(): string
    {
        return (string)file_get_contents(__DIR__ . '/../../templates/admin/links.html.twig');
    }
}
