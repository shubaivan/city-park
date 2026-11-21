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
        foreach ([$passScan, $residentScan, $unknown] as $i => $scan) {
            $this->setId($scan, 10 + $i);
            (new \ReflectionProperty(QrScan::class, 'created_at'))->setValue($scan, (clone $now)->modify('-' . (10 - $i) . ' minutes'));
        }

        $scans = $this->createStub(QrScanRepository::class);
        $scans->method('between')->willReturn([$passScan, $residentScan, $unknown]);
        $scans->method('newerThan')->willReturn([$passScan, $residentScan, $unknown]);
        $gate = $this->createStub(GateEventRepository::class);
        $gate->method('between')->willReturn([]);
        $gate->method('newerThan')->willReturn([]);

        $passes = $this->createMock(GuestPassRepository::class);
        $passes->expects($this->once())->method('withIssuers')->with([4])->willReturn([$pass]);
        $passes->method('liveWithIssuers')->willReturn([]);
        $people = $this->createMock(TelegramUserRepository::class);
        $people->expects($this->once())->method('linkedTo')->with([12])->willReturn([$neighbour]);

        $container = static::getContainer();
        $container->set(QrScanRepository::class, $scans);
        $container->set(GateEventRepository::class, $gate);
        $container->set(GuestPassRepository::class, $passes);
        $container->set(TelegramUserRepository::class, $people);

        return $client;
    }

    public function testThePollGivesTheGuardAPhoneOnEveryRowAndNoCardLinks(): void
    {
        $client = $this->client('test_guard');
        $client->request('GET', '/admin/scans/feed?scan=0&event=0');

        $this->assertResponseIsSuccessful();
        $rows = json_decode((string)$client->getResponse()->getContent(), true)['rows'];
        $html = array_column($rows, 'html', 'key');

        $this->assertCount(3, $html);
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
}
