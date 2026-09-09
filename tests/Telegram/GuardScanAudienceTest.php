<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Repository\AccountRepository;
use App\Repository\ScheduledSetRepository;
use App\Service\GuardService;
use App\Service\SchedulePavilionService;
use App\Service\TelegramUserService;
use App\Telegram\Guard\Command\GuardScanCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What each reader is told when they scan somebody's QR.
 *
 * The scan used to answer the guard and nobody else. Иван opened it to the house on
 * 09.09.2026 — «я хотел бы чтоб кто угодно из ЖК мог проверить кого угодно, а не только
 * охрана» — which is the right call for a check that two people cannot possibly perform for
 * 457, and it changes what the answer may contain.
 *
 * **The flat goes only to the guard.** His check *is* «яка квартира»; a neighbour's is «чи
 * це мешканець і чи є в них бронь», and the flat adds nothing to that while the picture is
 * forwardable — one screenshot in the house chat would otherwise name a flat to everyone.
 * Same call, and the same trap, as `board()`'s `namesFlats`.
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

    public function testTheGuardIsToldTheFlat(): void
    {
        $text = $this->scan(self::GUARD_TELEGRAM_ID, booked: true);

        $this->assertStringContainsString('Все вірно', $text);
        $this->assertStringContainsString(self::FLAT, $text, 'the flat is the guard’s whole check');
    }

    public function testAResidentIsToldValidityAndTheHoursButNeverTheFlat(): void
    {
        $text = $this->scan('888111', booked: true);

        $this->assertStringContainsString('Код дійсний', $text);
        $this->assertStringContainsString('18:00', $text, 'the hours are the answer to «вони тут по броні?»');
        $this->assertStringNotContainsString(
            self::FLAT,
            $text,
            'a forwarded screenshot must not name somebody’s flat to the whole house',
        );
    }

    /** Валідний код без броні: an expired screenshot has to read as plainly wrong. */
    public function testAResidentIsToldWhenThereIsNoBooking(): void
    {
        $text = $this->scan('888111', booked: false);

        $this->assertStringContainsString('Код дійсний', $text);
        $this->assertStringContainsString('немає', $text);
        $this->assertStringNotContainsString(self::FLAT, $text);
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
    private function scan(string $scannerTelegramId, bool $booked, bool $linked = true): string
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
        $users->method('resolveAccount')->willReturn($linked ? $this->account(9, '12') : null);

        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('find')->willReturn($holder);

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate(self::getContainer());

        (new GuardScanCommand($guard, $users, $accounts))($bot, $guard->mintToken($holder));

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
