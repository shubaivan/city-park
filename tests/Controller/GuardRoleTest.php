<?php

namespace App\Tests\Controller;

use App\EventSubscriber\GuardPanelScopeSubscriber;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The gate's panel login (ROLE_GUARD) reads the QR scan log and nothing else.
 *
 * Every /admin route the router knows is walked, not a hand-picked list: the exports, the
 * file downloads, the DataTables POSTs and whatever is added next are the ones a list
 * written today would forget. Refusals are asserted as 403 exactly — a 302 to /login is
 * what an unauthenticated client gets, and accepting it made ComplaintsRoleTest pass
 * once without signing anybody in.
 */
class GuardRoleTest extends WebTestCase
{
    private const LOGIN = 'test_guard';

    private function signedInGuard(): KernelBrowser
    {
        $client = static::createClient();
        $provider = static::getContainer()->get('security.user.provider.concrete.users_in_memory');
        $client->loginUser($provider->loadUserByIdentifier(self::LOGIN));

        return $client;
    }

    public function testTheGuardReadsTheScanLog(): void
    {
        $client = $this->signedInGuard();
        $client->request('GET', '/admin/scans');

        $status = $client->getResponse()->getStatusCode();
        $this->assertNotSame(403, $status, '/admin/scans must stay readable for the guard');
        $this->assertNotSame(302, $status, 'not signed in — this test would prove nothing');
    }

    public function testTheDashboardSendsTheGuardToTheScanLog(): void
    {
        $client = $this->signedInGuard();
        $client->request('GET', '/admin');

        $this->assertSame(302, $client->getResponse()->getStatusCode());
        $this->assertStringEndsWith('/admin/scans', (string)$client->getResponse()->headers->get('Location'));
    }

    /** @return array<string, array{string, string}> */
    public static function everyOtherAdminRoute(): array
    {
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');
        $cases = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();

            if (!str_starts_with($path, '/admin') || $path === '/admin'
                || in_array($name, GuardPanelScopeSubscriber::ALLOWED_ROUTES, true)) {
                continue;
            }

            // A literal requirement (`size: full`) is used as is; anything else gets a value
            // that matches, so the request reaches the firewall rather than a 404.
            $path = preg_replace_callback('/\{(\w+)\}/', static fn (array $m) => match (true) {
                (bool)preg_match('/^[a-z]+$/', (string)$route->getRequirement($m[1])) => $route->getRequirement($m[1]),
                $m[1] === 'id' => '1',
                $m[1] === 'kind' => 'debt',
                default => 'x',
            }, $path);

            foreach ($route->getMethods() ?: ['GET', 'POST'] as $method) {
                $cases[$method . ' ' . $path] = [$method, $path];
            }
        }

        self::ensureKernelShutdown();

        return $cases;
    }

    /** @dataProvider everyOtherAdminRoute */
    public function testEveryOtherAdminRouteIsRefusedToTheGuard(string $method, string $path): void
    {
        $client = $this->signedInGuard();
        $client->request($method, $path);

        $this->assertSame(
            403,
            $client->getResponse()->getStatusCode(),
            sprintf('%s %s must be refused to the guard login', $method, $path),
        );
    }

    /** The provider walk is not empty, or the test above passes on nothing. */
    public function testTheWalkCoversTheRegisters(): void
    {
        $cases = self::everyOtherAdminRoute();

        foreach (['GET /admin/users', 'GET /admin/objects', 'GET /admin/sms', 'GET /admin/cars.csv',
                     'POST /admin/users/data-table', 'GET /admin/logins', 'GET /admin/avatar/1/full'] as $case) {
            $this->assertArrayHasKey($case, $cases);
        }
    }

    /**
     * Signing in for real, through the form, lands on the scan log — not on the dashboard
     * and not on the page the session was bounced from before it signed in.
     */
    public function testSigningInLandsOnTheScanLog(): void
    {
        $password = bin2hex(random_bytes(8));
        $before = [$_SERVER['TEST_GUARD_PASSWORD_HASH'] ?? '', $_ENV['TEST_GUARD_PASSWORD_HASH'] ?? ''];
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
        $_SERVER['TEST_GUARD_PASSWORD_HASH'] = $_ENV['TEST_GUARD_PASSWORD_HASH'] = $hash;

        try {
            $client = static::createClient();
            // Bounced from the people register first: that page must not be where he returns.
            $client->request('GET', '/admin/users');
            $this->assertSame(302, $client->getResponse()->getStatusCode());

            $crawler = $client->request('GET', '/login');
            $form = $crawler->filter('form')->form([
                '_username' => self::LOGIN,
                '_password' => $password,
            ]);
            $client->submit($form, [], [
                'HTTP_ORIGIN' => 'http://localhost',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ]);

            $location = (string)$client->getResponse()->headers->get('Location');
            $this->assertSame(302, $client->getResponse()->getStatusCode());
            $this->assertStringEndsWith('/admin/scans', $location, 'the guard must land on the scan log');
        } finally {
            [$_SERVER['TEST_GUARD_PASSWORD_HASH'], $_ENV['TEST_GUARD_PASSWORD_HASH']] = $before;
        }
    }

    /** The bar and the page offer nothing he would only be refused at. */
    public function testTheScanLogLinksNowhereElseForTheGuard(): void
    {
        $client = $this->signedInGuard();
        $twig = static::getContainer()->get('twig');
        static::getContainer()->get('request_stack')->push(\Symfony\Component\HttpFoundation\Request::create('/admin/scans'));
        $token = static::getContainer()->get('security.untracked_token_storage')->getToken();
        static::getContainer()->get('security.token_storage')->setToken(
            $token ?? new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(
                static::getContainer()->get('security.user.provider.concrete.users_in_memory')->loadUserByIdentifier(self::LOGIN),
                'main',
                ['ROLE_GUARD'],
            ),
        );

        $account = (new \App\Entity\Account())->setAccountNumber('220085')->setApartmentNumber('85')->setHouseNumber('19')->setStreet('Козацька');
        (new \ReflectionProperty(\App\Entity\Account::class, 'id'))->setValue($account, 7);
        $issuer = (new \App\Entity\TelegramUser())->setFirstName('Ніка')->setLastName('');
        (new \ReflectionProperty(\App\Entity\TelegramUser::class, 'id'))->setValue($issuer, 9);
        $pass = (new \App\Entity\GuestPass())->setAccount($account)->setLabel('Бригада')->setIssuedBy($issuer);
        $scan = (new \App\Entity\QrScan())->setKind('resident')->setResult('ok')
            ->setScannedByLabel('Охорона')->setSubjectLabel('буд. 19, кв. 85')->setSubjectAccount($account);

        $html = $twig->render('admin/scans.html.twig', [
            'entries' => [$scan],
            'passes' => [$pass],
            'total' => 1,
            'shown' => 300,
            'now' => new \DateTime('now', new \DateTimeZone('Europe/Kyiv')),
        ]);

        $this->assertStringContainsString('Сканування QR', $html);
        $this->assertStringContainsString('буд. 19', $html);
        preg_match_all('/href="([^"]+)"/', $html, $m);
        foreach ($m[1] as $href) {
            if (str_starts_with($href, '#') || str_contains($href, '/build/') || str_ends_with($href, '.jpg')) {
                continue;
            }
            $this->assertContains($href, ['/admin/scans', '/logout'], 'the guard is offered a link to ' . $href);
        }
    }
}
