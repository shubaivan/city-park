<?php

namespace App\Tests\Controller;

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
     * The rule the template has to keep: the two admin-only pages are linked to only when
     * the reader is an admin.
     */
    public function testTheTemplateGuardsTheAdminOnlyLinks(): void
    {
        $template = (string)file_get_contents(__DIR__ . '/../../templates/admin/links.html.twig');

        foreach (['app_admin_services', 'app_admin_rentals'] as $route) {
            $this->assertStringContainsString(
                $route,
                $template,
                $route . ': the log should link to what was clicked',
            );
        }

        $this->assertSame(
            2,
            substr_count($template, "is_granted('ROLE_ADMIN')"),
            'both link builders must be gated, or the complaints role gets a 403 on a click',
        );
    }
}
