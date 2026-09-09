<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Repository\AccountRepository;
use App\Repository\ScheduledSetRepository;
use App\Service\GuardService;
use App\Service\GuestPassService;
use App\Service\SchedulePavilionService;
use App\Service\TelegramUserService;
use App\Telegram\Guard\Command\GuardScanCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a scan answers, and to whom.
 *
 * The scan used to answer the guard and nobody else. Иван opened it to the house on
 * 09.09.2026 — «считать код и получить информацию может любой подтвержденный житель ЖК» —
 * which is the right call for a check two people cannot perform for 457.
 *
 * **One answer, the same for everybody who may read it**, the flat included. A guard-only
 * version was tried for an evening and was one rule too many: the code is shown
 * deliberately, by its owner, to somebody standing in front of them, and «це мешканець»
 * without saying which flat answers nothing they could not already see.
 *
 * Driven through FakeNutgram rather than read out of the source: an earlier version of this
 * test matched the code with a regex, and a deliberately leaking version passed it.
 */
class GuardScanAudienceTest extends KernelTestCase
{
    private const GUARD_TELEGRAM_ID = '777000';
    private const SECRET = 'test-secret';

    /** «буд. 19, кв. 85» — the string that must never reach a neighbour. */
    private const FLAT = 'кв. 85';

    /** The guard's answer and a neighbour's are the same string. */
    public function testEveryReaderGetsTheSameAnswer(): void
    {
        $guard = $this->scan(self::GUARD_TELEGRAM_ID, booked: true);
        $resident = $this->scan('888111', booked: true);

        $this->assertStringContainsString('Код дійсний', $guard);
        $this->assertStringContainsString(self::FLAT, $guard);
        $this->assertSame($guard, $resident, 'one answer: a second version is a rule that can leak');
    }

    /** The flat and the hours: «хто це» and «чи вони тут по броні» in one line each. */
    public function testTheAnswerNamesTheFlatAndTheBooking(): void
    {
        $text = $this->scan('888111', booked: true);

        $this->assertStringContainsString('мешканець нашого ЖК', $text);
        $this->assertStringContainsString(self::FLAT, $text);
        $this->assertStringContainsString('буд. 19', $text, 'five buildings repeat their flat numbers');
        $this->assertStringContainsString('18:00', $text);
    }

    /** Валідний код без броні: an expired screenshot has to read as plainly wrong. */
    public function testAValidCodeWithNoBookingSaysSo(): void
    {
        $text = $this->scan('888111', booked: false);

        $this->assertStringContainsString('Код дійсний', $text);
        $this->assertStringContainsString(self::FLAT, $text);
        $this->assertStringContainsString('немає', $text);
        $this->assertStringNotContainsString('18:00', $text);
    }

    /**
     * A blocked reader is still a resident.
     *
     * A debt or a missed photo stops somebody booking; refusing them the right to check a
     * neighbour's code protects nothing and tells them nothing they could not see by
     * walking past.
     */
    public function testABlockedResidentStillGetsTheAnswer(): void
    {
        $text = $this->scan('888111', booked: true, blockedReader: true);

        $this->assertStringContainsString('Код дійсний', $text);
        $this->assertStringContainsString(self::FLAT, $text);
    }

    /** An unlinked visitor learns what the code is and nothing about its holder. */
    public function testAnUnlinkedVisitorIsToldNothing(): void
    {
        $text = $this->scan('999222', booked: true, linked: false);

        $this->assertStringContainsString('підтверджені мешканці', $text);
        $this->assertStringNotContainsString(self::FLAT, $text);
        $this->assertStringNotContainsString('18:00', $text);
    }

    /** Renders one scan and returns the text the bot sent back. */
    private function scan(
        string $scannerTelegramId,
        bool $booked,
        bool $linked = true,
        bool $blockedReader = false,
    ): string
    {
        self::bootKernel();

        $holder = $this->account(7, '85');
        $bookings = $this->createMock(ScheduledSetRepository::class);
        $guard = new GuardService($bookings, self::GUARD_TELEGRAM_ID, self::SECRET);

        // runningSessionFor() goes through the query builder, which a mock cannot answer;
        // the session is handed over by stubbing that one method instead.
        $guard = new class ($bookings, self::GUARD_TELEGRAM_ID, self::SECRET, $booked ? $this->session($holder) : null) extends GuardService {
            public function __construct(
                ScheduledSetRepository $bookings,
                string $ids,
                string $secret,
                private ?array $session = null,
            ) {
                parent::__construct($bookings, $ids, $secret);
            }

            public function runningSessionFor(Account $account, \DateTimeInterface $now): ?array
            {
                return $this->session;
            }
        };

        $scanner = new TelegramUser();
        $scanner->setTelegramId($scannerTelegramId);

        $users = $this->createMock(TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn($scanner);
        $reader = $linked ? $this->account(9, '12') : null;
        $reader?->setIsActive(!$blockedReader);
        $users->method('resolveAccount')->willReturn($reader);

        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('find')->willReturn($holder);

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate(self::getContainer());

        // The scan log is written through GuestPassService; mocked, because this test is
        // about what the reader is told, and a database would make it the only one here
        // that needs one.
        $passes = $this->createMock(GuestPassService::class);

        (new GuardScanCommand($guard, $users, $accounts, $passes))($bot, $guard->mintToken($holder));

        return $this->lastText($bot);
    }

    private function lastText(Nutgram $bot): string
    {
        $sent = '';

        // The body is the JSON Telegram would have received.
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
            ->setHouseNumber('19')
            ->setStreet('Козацька');
        $account->setIsActive(true);

        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }

    /** @return array{pavilion:int, start:\DateTimeImmutable, end:\DateTimeImmutable, account:?Account, user:TelegramUser} */
    private function session(Account $account): array
    {
        $user = new TelegramUser();
        $user->setTelegramId('1');
        $user->setAccount($account);

        $day = SchedulePavilionService::createNewDate();

        return [
            'pavilion' => 2,
            'start' => new \DateTimeImmutable($day->format('Y-m-d') . ' 18:00', $day->getTimezone()),
            'end' => new \DateTimeImmutable($day->format('Y-m-d') . ' 21:00', $day->getTimezone()),
            'account' => $account,
            'user' => $user,
        ];
    }
}
