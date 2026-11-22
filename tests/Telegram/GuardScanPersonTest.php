<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\QrScan;
use App\Entity\TelegramUser;
use App\Repository\AccountRepository;
use App\Repository\ScheduledSetRepository;
use App\Repository\TelegramUserRepository;
use App\Service\GuardService;
use App\Service\GuestPassService;
use App\Service\TelegramUserService;
use App\Telegram\Guard\Command\GuardScanCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A personal resident code, scanned (22.11.2026): the guard is told *who* — name, role,
 * phone — the log keeps the person, an old flat-level code still works, and a person
 * unlinked from the flat holds a dead picture.
 *
 * Driven through FakeNutgram and asserted on what was actually sent, like
 * GuardScanAudienceTest.
 */
class GuardScanPersonTest extends KernelTestCase
{
    private const GUARD = '777000';
    private const SECRET = 'test-secret';

    /** @var list<array<int, mixed>> recordScan() calls */
    private array $recorded = [];

    public function testTheGuardIsToldWhoseCodeItIs(): void
    {
        [$text] = $this->scan(self::GUARD, personal: true);

        $this->assertStringContainsString('Код дійсний', $text);
        $this->assertStringContainsString('Чий код:', $text);
        $this->assertStringContainsString('Олена Петренко', $text);
        $this->assertStringContainsString('Член сім&#039;ї', $text, 'the role, HTML-escaped for parse_mode HTML');
        $this->assertStringContainsString('Інші з квартири', $text);
        $this->assertStringContainsString('+380501234567', $text);
        // The rest of the flat follows, to ring.
        $this->assertStringContainsString('Петро Петренко', $text);
        $this->assertStringContainsString('+380671112233', $text);
    }

    public function testTheLogKeepsThePerson(): void
    {
        $this->scan(self::GUARD, personal: true);

        $call = $this->recorded[0];
        $this->assertSame(QrScan::RESULT_NO_BOOKING, $call[1]);
        $this->assertInstanceOf(TelegramUser::class, $call[6] ?? null);
        $this->assertSame(42, $call[6]->getId());
    }

    /** A neighbour checking a code keeps the address and nothing more — personal code or not. */
    public function testANeighbourIsNotToldTheName(): void
    {
        [$text] = $this->scan('999111', personal: true);

        $this->assertStringContainsString('Код дійсний', $text);
        $this->assertStringContainsString('кв. 1', $text);
        $this->assertStringNotContainsString('Олена', $text);
        $this->assertStringNotContainsString('+380', $text);
    }

    /** Every flat-level picture already in a phone keeps working; the person is «невідомо». */
    public function testAnOldFlatLevelCodeStillWorks(): void
    {
        [$text] = $this->scan(self::GUARD, personal: false);

        $this->assertStringContainsString('Код дійсний', $text);
        $this->assertStringContainsString('старий код квартири', $text);
        $this->assertStringContainsString('Олена Петренко', $text, 'the flat\'s people are still listed to ring');
        $this->assertNull($this->recorded[0][6] ?? null, 'no person in the log');
    }

    public function testAPersonUnlinkedFromTheFlatHoldsADeadCode(): void
    {
        [$text] = $this->scan(self::GUARD, personal: true, relink: true, personAccount: null);

        $this->assertStringContainsString('Код більше не дійсний', $text);
        $this->assertStringNotContainsString('Код дійсний', $text);
        $this->assertStringNotContainsString('Олена', $text);
        $this->assertSame(QrScan::RESULT_PERSON_GONE, $this->recorded[0][1]);
    }

    public function testAPersonMovedToAnotherFlatHoldsADeadCode(): void
    {
        $other = $this->account(8, '2');
        [$text] = $this->scan(self::GUARD, personal: true, relink: true, personAccount: $other);

        $this->assertStringContainsString('Код більше не дійсний', $text);
        $this->assertSame(QrScan::RESULT_PERSON_GONE, $this->recorded[0][1]);
    }

    public function testAPersonGoneFromTheBotHoldsADeadCode(): void
    {
        [$text] = $this->scan(self::GUARD, personal: true, personExists: false);

        $this->assertStringContainsString('Код більше не дійсний', $text);
        $this->assertSame(QrScan::RESULT_PERSON_GONE, $this->recorded[0][1]);
    }

    /** A tampered personal code is no code: never reaches a lookup, logged as unknown. */
    public function testATamperedPersonalCodeIsUnknown(): void
    {
        [$text] = $this->scan(self::GUARD, personal: true, tamper: true);

        $this->assertStringContainsString('Код не розпізнано', $text);
        $this->assertSame(QrScan::RESULT_UNKNOWN, $this->recorded[0][1]);
    }

    /**
     * @return array{0:string}
     */
    private function scan(
        string $scannerTelegramId,
        bool $personal,
        bool $relink = false,
        ?Account $personAccount = null,
        bool $personExists = true,
        bool $tamper = false,
    ): array {
        self::bootKernel();
        $this->recorded = [];

        $flat = $this->account(7, '1');
        $holder = (new TelegramUser())->setFullName('Олена Петренко')->setPhoneNumber('380501234567')->setRole(TelegramUser::ROLE_FAMILY);
        (new \ReflectionProperty(TelegramUser::class, 'id'))->setValue($holder, 42);
        $other = (new TelegramUser())->setFullName('Петро Петренко')->setPhoneNumber('380671112233')->setRole(TelegramUser::ROLE_OWNER);
        (new \ReflectionProperty(TelegramUser::class, 'id'))->setValue($other, 43);
        $other->setAccount($flat);
        $flat->getUsers()->add($holder);
        $flat->getUsers()->add($other);

        // Minted while the holder was on the flat; the link may have changed since.
        $holder->setAccount($flat);
        // runningSessionFor() needs a query builder a mock cannot answer: no booking running.
        $guard = new class ($this->createMock(ScheduledSetRepository::class), self::GUARD, self::SECRET) extends GuardService {
            public function runningSessionFor(Account $account, \DateTimeInterface $now): ?array
            {
                return null;
            }
        };
        $token = $personal ? $guard->mintToken($flat, $holder) : $guard->mintToken($flat);
        if ($tamper) {
            $token = str_replace('g-7-42-', 'g-7-43-', $token);
        }
        if ($relink) {
            $holder->setAccount($personAccount);
        }

        $scanner = (new TelegramUser())->setTelegramId($scannerTelegramId);
        $users = $this->createMock(TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn($scanner);
        $users->method('resolveAccount')->willReturn($this->account(9, '12'));

        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('find')->willReturnCallback(fn ($id) => $id === 7 ? $flat : null);
        $people = $this->createMock(TelegramUserRepository::class);
        $people->method('find')->willReturnCallback(fn ($id) => $personExists && $id === 42 ? $holder : null);

        $passes = $this->createMock(GuestPassService::class);
        $passes->method('recordScan')->willReturnCallback(function (...$args) {
            $this->recorded[] = $args;

            return null;
        });

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate(self::getContainer());

        (new GuardScanCommand($guard, $users, $accounts, $passes, null, null, $people))($bot, $token);

        return [$this->lastText($bot)];
    }

    private function lastText(Nutgram $bot): string
    {
        $sent = '';

        foreach ($bot->getRequestHistory() as $request) {
            $fields = json_decode((string)$request['request']->getBody(), true);
            $sent .= (is_array($fields) ? ($fields['text'] ?? '') : '') . "\n";
        }

        return $sent;
    }

    private function account(int $id, string $apartment): Account
    {
        $account = (new Account())
            ->setAccountNumber('2200' . $apartment)
            ->setApartmentNumber($apartment)
            ->setHouseNumber('17')
            ->setStreet('Козацька');
        $account->setIsActive(true);
        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }
}
