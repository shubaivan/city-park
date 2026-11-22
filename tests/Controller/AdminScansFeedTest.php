<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\GateEvent;
use App\Entity\GuestPass;
use App\Entity\QrScan;
use App\Entity\TelegramUser;
use App\Repository\GateEventRepository;
use App\Repository\GuestPassRepository;
use App\Repository\QrScanRepository;
use App\Repository\TelegramUserRepository;
use App\Service\GuardCalendar;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Who to ring on every feed line (Иван, 21.11.2026), through the real controllers and the
 * real security: the page and the 10-second poll both load the issuer and the flat's
 * people and draw them. The repositories are stubbed — there is no test database — so what
 * is pinned is the wiring: the right ids asked for, the answer reaching every row, the
 * gate's login getting the phone and no link into a card it is refused at.
 */
class AdminScansFeedTest extends WebTestCase
{
    /** @var list<array{string, array<int, mixed>}> */
    private array $asked = [];

    private function client(string $login): KernelBrowser
    {
        $client = static::createClient();
        $provider = static::getContainer()->get('security.user.provider.concrete.users_in_memory');
        $client->loginUser($provider->loadUserByIdentifier($login));

        $flat = (new Account())->setAccountNumber('220012')->setApartmentNumber('12')->setHouseNumber('19')->setStreet('Козацька');
        $this->setId($flat, 12);

        $issuer = (new TelegramUser())->setFirstName('Nina')->setLastName('K')->setFullName('Коваль Ніна Петрівна')->setPhoneNumber('+380 (67) 111-22-33');
        $this->setId($issuer, 5);
        $neighbour = (new TelegramUser())->setPhoneNumber('380501234567')->setAccount($flat);
        $this->setId($neighbour, 6);

        $pass = (new GuestPass())->setAccount($flat)->setLabel('Бригада')->setIssuedBy($issuer);
        $this->setId($pass, 4);

        $now = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $passScan = (new QrScan())->setKind(QrScan::KIND_GUEST)->setResult(QrScan::RESULT_REVOKED)->setGuestPassId(4)
            ->setSubjectLabel('Бригада')->setScannedByLabel('Охорона');
        $residentScan = (new QrScan())->setKind(QrScan::KIND_RESIDENT)->setResult(QrScan::RESULT_OK)->setSubjectAccount($flat)
            ->setSubjectLabel('буд. 19, кв. 12')->setScannedByLabel('Охорона');
        $unknown = (new QrScan())->setKind(QrScan::KIND_RESIDENT)->setResult(QrScan::RESULT_UNKNOWN)
            ->setSubjectLabel('—')->setScannedByLabel('Охорона');
        // A personal code (22.11.2026): the neighbour's own QR.
        $holder = (new TelegramUser())->setFullName('Олена Петренко')->setRole(TelegramUser::ROLE_OWNER)->setPhoneNumber('380509998877')->setAccount($flat);
        $this->setId($holder, 8);
        $personal = (new QrScan())->setKind(QrScan::KIND_RESIDENT)->setResult(QrScan::RESULT_OK)->setSubjectAccount($flat)
            ->setSubjectLabel('буд. 19, кв. 12')->setScannedByLabel('Охорона')
            ->setSubjectUser($holder)->setSubjectUserLabel('Олена Петренко · Власник');
        foreach ([$passScan, $residentScan, $unknown, $personal] as $i => $scan) {
            $this->setId($scan, 10 + $i);
            (new \ReflectionProperty(QrScan::class, 'created_at'))->setValue($scan, (clone $now)->modify('-' . (10 - $i) . ' minutes'));
        }

        // What the repositories were asked for: the pass filter must reach every query.
        $this->asked = [];
        $record = function (string $what, array $args, array $result): array {
            $this->asked[] = [$what, $args];

            return $result;
        };
        $all = [$passScan, $residentScan, $unknown, $personal];
        $pick = static fn (array $a): array => ($a[3] ?? null) === 4 ? [$passScan] : (($a[4] ?? null) === 8 ? [$personal] : $all);
        $scans = $this->createStub(QrScanRepository::class);
        $scans->method('between')->willReturnCallback(fn (...$a) => $record('scans', $a, $pick($a)));
        $scans->method('newerThan')->willReturnCallback(fn (...$a) => $record('scans', $a, $pick($a)));
        $gate = $this->createStub(GateEventRepository::class);
        $gate->method('between')->willReturnCallback(fn (...$a) => $record('gate', $a, []));
        $gate->method('newerThan')->willReturnCallback(fn (...$a) => $record('gate', $a, []));

        $passes = $this->createStub(GuestPassRepository::class);
        $passes->method('withIssuers')->willReturnCallback(fn (array $ids) => $record('passes', [$ids], in_array(4, $ids, true) ? [$pass] : []));
        $passes->method('liveWithIssuers')->willReturn([]);
        $people = $this->createStub(TelegramUserRepository::class);
        $people->method('find')->willReturnCallback(fn ($id) => $record('person', [$id], [$id === 8 ? $holder : null])[0]);
        $people->method('linkedTo')->willReturnCallback(fn (array $ids) => $record('people', [$ids], in_array(12, $ids, true) ? [$neighbour] : []));
        $calendar = $this->createStub(GuardCalendar::class);
        $calendar->method('counts')->willReturnCallback(fn (...$a) => $record('calendar', $a, []));

        $container = static::getContainer();
        $container->set(QrScanRepository::class, $scans);
        $container->set(GateEventRepository::class, $gate);
        $container->set(GuestPassRepository::class, $passes);
        $container->set(TelegramUserRepository::class, $people);
        $container->set(GuardCalendar::class, $calendar);

        return $client;
    }

    public function testThePollGivesTheGuardAPhoneOnEveryRowAndNoCardLinks(): void
    {
        $client = $this->client('test_guard');
        $client->request('GET', '/admin/scans/feed?scan=0&event=0');

        $this->assertResponseIsSuccessful();
        $rows = json_decode((string)$client->getResponse()->getContent(), true)['rows'];
        $html = array_column($rows, 'html', 'key');

        $this->assertCount(4, $html);
        $this->assertStringContainsString('href="/admin/scans?person=8#person-filter"', $html['s13']);
        $this->assertStringContainsString('Власник', $html['s13']);
        $this->assertStringContainsString('Видав: Коваль Ніна Петрівна', $html['s10'], 'the registry name, as the bot writes it');
        $this->assertStringContainsString('href="tel:+380671112233"', $html['s10'], 'every non-digit stripped');
        $this->assertStringContainsString('href="tel:+380501234567"', $html['s11']);
        $this->assertMatchesRegularExpression('/Контакт:\s*—/u', $html['s12']);

        foreach ($html as $row) {
            $this->assertStringNotContainsString('/admin/users/', $row);
        }
    }

    public function testThePageCarriesTheContactsAndANamelessPersonIsADash(): void
    {
        $client = $this->client('test_guard');
        $client->request('GET', '/admin/scans');

        $this->assertResponseIsSuccessful();
        $page = (string)$client->getResponse()->getContent();
        $this->assertStringContainsString('href="tel:+380671112233"', $page);
        $this->assertStringContainsString('href="tel:+380501234567"', $page);
        $this->assertMatchesRegularExpression('/Контакт:\s*—\s*<span class="text-nowrap">\(<a href="tel:\+380501234567"/u', $page, 'a person with no name reads «—», never an empty name');
        $this->assertStringNotContainsString('/admin/users/', $page);
        $this->assertDoesNotMatchRegularExpression('/<a [^>]*>\s*<\/a>/u', $page, 'no empty link');
    }

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, $id);
    }

    /** `?pass=` reaches every query of the poll, and the poll never asks the page to reload for a new week. */
    public function testThePollOfOnePassAsksForThatPassOnly(): void
    {
        $client = $this->client('test_guard');
        $client->request('GET', '/admin/scans/feed?scan=0&event=0&pass=4');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string)$client->getResponse()->getContent(), true);
        $this->assertSame('pass', $data['week']);
        $this->assertSame(['s10'], array_column($data['rows'], 'key'));

        foreach ($this->asked as [$what, $args]) {
            if (in_array($what, ['scans', 'gate'], true)) {
                $this->assertSame(4, $args[3], $what . ' must be asked for pass 4 only');
            }
        }
    }

    /** The pass's own page, for the gate: the pass named, its lines, the calendar counted for it. */
    public function testThePassPageForTheGuard(): void
    {
        $client = $this->client('test_guard');
        $client->request('GET', '/admin/scans?pass=4');

        $this->assertResponseIsSuccessful();
        $page = (string)$client->getResponse()->getContent();
        $this->assertStringContainsString('Показано: <b>👷 пропуск №4</b>', $page);
        $this->assertStringContainsString('<b>Бригада</b>', $page);
        $this->assertStringContainsString('✕ Усі події', $page);
        $this->assertStringContainsString('href="tel:+380671112233"', $page);
        $this->assertStringContainsString('data-key="s10"', $page);
        $this->assertStringNotContainsString('data-key="s11"', $page, 'the resident line is not this pass\'s');
        $this->assertStringNotContainsString('data-key="s12"', $page);
        $this->assertStringNotContainsString('/admin/users/', $page);

        $asked = array_filter($this->asked, static fn ($a) => in_array($a[0], ['scans', 'gate', 'calendar'], true));
        $this->assertNotEmpty($asked);
        foreach ($asked as [$what, $args]) {
            $this->assertSame(4, $what === 'calendar' ? $args[1] : $args[3], $what . ' must be filtered to pass 4');
        }
    }

    /** Anything that is not a pass id is no filter at all — never an error, never «pass 0». */
    public function testAGarbagePassIsNoFilter(): void
    {
        $client = $this->client('test_guard');
        $client->request('GET', '/admin/scans?pass=abc');

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('✕ Усі події', (string)$client->getResponse()->getContent());
        foreach ($this->asked as [$what, $args]) {
            if ($what === 'calendar') {
                $this->assertNull($args[1]);
            }
        }
    }

    /** `?person=` reaches every query, the calendar and the poll; the page shows the chip. */
    public function testThePersonPageForTheGuard(): void
    {
        $client = $this->client('test_guard');
        $client->request('GET', '/admin/scans?person=8');

        $this->assertResponseIsSuccessful();
        $page = (string)$client->getResponse()->getContent();
        $this->assertStringContainsString('Показано: <b>👤 Олена Петренко</b>', $page);
        $this->assertStringContainsString('✕ Усі події', $page);
        $this->assertStringContainsString('data-key="s13"', $page);
        $this->assertStringNotContainsString('data-key="s11"', $page, 'a flat-level scan names nobody');
        $this->assertStringNotContainsString('/admin/users/', $page);

        $asked = array_filter($this->asked, static fn ($a) => in_array($a[0], ['scans', 'gate', 'calendar'], true));
        $this->assertNotEmpty($asked);
        foreach ($asked as [$what, $args]) {
            $this->assertSame(8, $what === 'calendar' ? $args[2] : $args[4], $what . ' must be filtered to person 8');
            $this->assertNull($what === 'calendar' ? $args[1] : $args[3], 'and not to a pass');
        }
    }

    public function testThePollOfOnePersonAsksForThatPersonOnly(): void
    {
        $client = $this->client('test_guard');
        $client->request('GET', '/admin/scans/feed?scan=0&event=0&person=8');

        $data = json_decode((string)$client->getResponse()->getContent(), true);
        $this->assertSame('person', $data['week']);
        $this->assertSame(['s13'], array_column($data['rows'], 'key'));
        foreach ($this->asked as [$what, $args]) {
            if (in_array($what, ['scans', 'gate'], true)) {
                $this->assertSame(8, $args[4]);
            }
        }
    }

    /** One filter at a time: a pass wins, a garbage person is no filter at all. */
    public function testPassWinsAndGarbagePersonIsNoFilter(): void
    {
        $client = $this->client('test_guard');
        $client->request('GET', '/admin/scans?pass=4&person=8');
        $this->assertStringContainsString('id="pass-filter"', (string)$client->getResponse()->getContent());
        foreach ($this->asked as [$what, $args]) {
            if (in_array($what, ['scans', 'gate'], true)) {
                $this->assertSame([4, null], [$args[3], $args[4]]);
            }
        }

        foreach (['abc', '0', '-1', '8 OR 1=1'] as $garbage) {
            self::ensureKernelShutdown();
            $client = $this->client('test_guard');
            $client->request('GET', '/admin/scans?person=' . rawurlencode($garbage));
            $this->assertResponseIsSuccessful();
            $this->assertStringNotContainsString('✕ Усі події', (string)$client->getResponse()->getContent());
            foreach ($this->asked as [$what, $args]) {
                if ($what === 'calendar') {
                    $this->assertNull($args[2]);
                }
            }
        }
    }
}
