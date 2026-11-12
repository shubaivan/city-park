<?php

namespace App\Tests\Controller;

use App\Entity\TelegramUser;
use App\Repository\ScheduledSetRepository;
use App\Service\GuardService;
use App\Service\PanelLoginLink;
use App\Service\PanelLoginTokenStore;
use App\Service\TelegramUserService;
use App\Telegram\Guard\Command\GuardPanelLoginCommand;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The gate signs in through the bot's one-time link, never with a password (Иван,
 * 12.11.2026): «🔐 Вхід у панель» for GUARD_TELEGRAM_IDS only, 5 minutes, once, only the
 * hash stored — and the session it opens reads the QR scan log and nothing else.
 *
 * The store is swapped for an in-memory one: the rules under test are the link's, and
 * this environment has no database.
 */
class GuardPanelLoginTest extends WebTestCase
{
    private const GUARD_ID = '111000111';
    private const RESIDENT_ID = '222000222';

    private KernelBrowser $client;
    private InMemoryPanelLoginTokenStore $store;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->store = new InMemoryPanelLoginTokenStore();

        $container = static::getContainer();
        $container->set(PanelLoginTokenStore::class, $this->store);
        $container->set(GuardService::class, new GuardService(
            $this->createMock(ScheduledSetRepository::class),
            self::GUARD_ID,
        ));
    }

    private function links(): PanelLoginLink
    {
        return static::getContainer()->get(PanelLoginLink::class);
    }

    private static function user(string $telegramId): TelegramUser
    {
        return (new TelegramUser())->setTelegramId($telegramId);
    }

    private function open(string $url): string
    {
        $this->client->request('GET', (string)parse_url($url, PHP_URL_PATH));

        return (string)$this->client->getResponse()->headers->get('Location');
    }

    public function testAValidLinkSignsTheGuardInOntoTheScanLogOnly(): void
    {
        $url = $this->links()->issue(self::user(self::GUARD_ID));
        $this->assertNotNull($url);
        $this->assertMatchesRegularExpression('#/panel/auth/[a-f0-9]{64}$#', $url);

        $this->assertStringEndsWith('/admin/scans', $this->open($url));

        $this->client->request('GET', '/admin/scans');
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertNotSame(403, $status);
        $this->assertNotSame(302, $status, 'the link did not leave a signed-in session');

        foreach (['/admin/users', '/admin/objects', '/admin/sms', '/admin/cars.csv', '/admin/logins'] as $path) {
            $this->client->request('GET', $path);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), $path);
        }

        $this->client->request('GET', '/admin');
        $this->assertStringEndsWith('/admin/scans', (string)$this->client->getResponse()->headers->get('Location'));
    }

    public function testOnlyTheHashIsStored(): void
    {
        $url = (string)$this->links()->issue(self::user(self::GUARD_ID));
        $token = basename($url);

        $this->assertArrayNotHasKey($token, $this->store->rows);
        $this->assertArrayHasKey(hash('sha256', $token), $this->store->rows);
    }

    public function testAReusedLinkIsRefused(): void
    {
        $url = (string)$this->links()->issue(self::user(self::GUARD_ID));
        $this->open($url);
        $this->client->request('GET', '/logout');

        $this->assertStringContainsString('/login?link=invalid', $this->open($url));
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        $token = str_repeat('ab', 32);
        $this->store->save(hash('sha256', $token), self::GUARD_ID, new \DateTimeImmutable('-1 minute', new \DateTimeZone('Europe/Kyiv')));

        $this->assertStringContainsString('/login?link=invalid', $this->open('/panel/auth/' . $token));
        $this->client->request('GET', '/admin/scans');
        $this->assertSame(302, $this->client->getResponse()->getStatusCode(), 'an expired link must not sign anybody in');
    }

    public function testTheLinkLivesFiveMinutes(): void
    {
        $this->links()->issue(self::user(self::GUARD_ID));
        $expires = array_values($this->store->rows)[0]['expires'];
        $minutes = ($expires->getTimestamp() - time()) / 60;

        $this->assertEqualsWithDelta(5, $minutes, 0.1);
    }

    public function testANonGuardGetsNoLinkAndAStoredOneIsRefused(): void
    {
        $this->assertNull($this->links()->issue(self::user(self::RESIDENT_ID)));
        $this->assertNull($this->links()->issue(null));
        $this->assertSame([], $this->store->rows);

        // A token minted for somebody who is not (or no longer) the gate opens nothing.
        $token = str_repeat('cd', 32);
        $this->store->save(hash('sha256', $token), self::RESIDENT_ID, new \DateTimeImmutable('+5 minutes', new \DateTimeZone('Europe/Kyiv')));

        $this->assertStringContainsString('/login?link=invalid', $this->open('/panel/auth/' . $token));
    }

    public function testAMalformedTokenIsNotEvenRouted(): void
    {
        $this->client->request('GET', '/panel/auth/not-a-token');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** @return string[] callbacks on the main menu as $telegramId would see it */
    private function menuFor(string $telegramId): array
    {
        $users = static::getContainer()->get(TelegramUserService::class);
        (new \ReflectionProperty(TelegramUserService::class, 'currentUser'))->setValue($users, self::user($telegramId));

        $markup = new \ReflectionMethod(StartCommand::class, 'mainMenuMarkup');
        $callbacks = [];

        foreach ($markup->invoke(null, static::getContainer()->get(Nutgram::class), null)->inline_keyboard as $row) {
            foreach ($row as $button) {
                $callbacks[] = $button->text . ' -> ' . ($button->callback_data ?? '?');
            }
        }

        return $callbacks;
    }

    public function testTheGuardHasTheButtonAndNobodyElseDoes(): void
    {
        $button = '🔐 Вхід у панель -> ' . GuardPanelLoginCommand::MENU_CALLBACK;

        $this->assertContains($button, $this->menuFor(self::GUARD_ID));
        $this->assertNotContains($button, $this->menuFor(self::RESIDENT_ID));
    }

    /** The button is routed, or it just spins. */
    public function testTheButtonIsRouted(): void
    {
        $config = (string)file_get_contents(__DIR__ . '/../../config/telegram.php');

        $this->assertStringContainsString('GuardPanelLoginCommand::MENU_CALLBACK, \\App\\Telegram\\Guard\\Command\\GuardPanelLoginCommand::class', $config);
        $this->assertStringContainsString("onCommand('panel', \\App\\Telegram\\Guard\\Command\\GuardPanelLoginCommand::class)", $config);
    }

    /** The login form never reaches the gate's user: it has no password to check. */
    public function testTheFormCannotSignInAsTheGuard(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form')->form(['_username' => 'ohorona', '_password' => 'anything']);
        $this->client->submit($form, [], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_SEC_FETCH_SITE' => 'same-origin']);

        $this->assertStringEndsWith('/login', (string)$this->client->getResponse()->headers->get('Location'));
        $this->client->request('GET', '/admin/scans');
        $this->assertSame(302, $this->client->getResponse()->getStatusCode());
    }
}

/** @internal */
final class InMemoryPanelLoginTokenStore implements PanelLoginTokenStore
{
    /** @var array<string, array{tg: string, expires: \DateTimeInterface, used: bool}> */
    public array $rows = [];

    public function save(string $tokenHash, string $telegramId, \DateTimeInterface $expiresAt): void
    {
        $this->rows[$tokenHash] = ['tg' => $telegramId, 'expires' => $expiresAt, 'used' => false];
    }

    public function consume(string $tokenHash, \DateTimeInterface $now): ?string
    {
        $row = $this->rows[$tokenHash] ?? null;

        if ($row === null || $row['used'] || $row['expires'] <= $now) {
            return null;
        }

        $this->rows[$tokenHash]['used'] = true;

        return $row['tg'];
    }
}
