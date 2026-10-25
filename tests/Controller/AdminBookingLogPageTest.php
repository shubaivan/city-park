<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\BookingLog;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use App\Service\BookingLogService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

/**
 * The booking journal: what it records about an hour, and that the page says it.
 *
 * It exists because a removed booking and a refused one left the same trace — a gap in
 * the id sequence — so the one thing it must never do is draw one as the other.
 */
class AdminBookingLogPageTest extends KernelTestCase
{
    private function set(): ScheduledSet
    {
        $account = (new Account())
            ->setAccountNumber('320085')
            ->setHouseNumber('21')
            ->setApartmentNumber('85')
            ->setStreet('Козацька');

        $user = (new TelegramUser())
            ->setFirstName('Ніка')
            ->setLastName('Тестова')
            ->setUsername('nika_test')
            ->setAccount($account);

        return (new ScheduledSet())
            ->setTelegramUserId($user)
            ->setYear(2026)->setMonth(10)->setDay(25)->setHour(13)
            ->setPavilion(2);
    }

    private function render(array $entries): string
    {
        self::bootKernel();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(new InMemoryUser('alina', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']),
        );

        return self::getContainer()->get(Environment::class)->render('admin/booking_log.html.twig', [
            'entries' => $entries,
            'total' => count($entries),
            'shown' => 400,
        ]);
    }

    public function testAnEntryKeepsTheFlatTheHourAndWhoDidIt(): void
    {
        $set = $this->set();
        $entry = BookingLogService::entry(BookingLog::ACTION_DELETED, $set, $set->getTelegramUserId());

        $this->assertSame('буд. 21, кв. 85', $entry->getPlaceLabel());
        $this->assertSame('Ніка Тестова @nika_test', $entry->getActorLabel());
        $this->assertSame('2026-10-25 13:00', $entry->getScheduledAt()->format('Y-m-d H:i'));
        $this->assertSame(2, $entry->getPavilion());
    }

    /** The resident read the refusal as HTML; the journal keeps the words, not the tags. */
    public function testARefusalKeepsWhatTheResidentWasToldWithoutMarkup(): void
    {
        $set = $this->set();
        $entry = BookingLogService::entry(
            BookingLog::ACTION_REFUSED,
            $set,
            $set->getTelegramUserId(),
            '<b>Ліміт</b> — не більше 3 годин на день &amp; 12 на місяць',
        );

        $this->assertSame('Ліміт — не більше 3 годин на день & 12 на місяць', $entry->getReason());
    }

    public function testEachActionIsDrawnAsItself(): void
    {
        $set = $this->set();
        $html = $this->render([
            BookingLogService::entry(BookingLog::ACTION_CREATED, $set, $set->getTelegramUserId()),
            BookingLogService::entry(BookingLog::ACTION_REFUSED, $set, $set->getTelegramUserId(), 'Хтось вже забронював'),
            BookingLogService::entry(BookingLog::ACTION_DELETED, $set, $set->getTelegramUserId()),
        ]);

        $this->assertStringContainsString('заброньовано', $html);
        $this->assertStringContainsString('відмова', $html);
        $this->assertStringContainsString('Хтось вже забронював', $html);
        $this->assertStringContainsString('видалено', $html);
        $this->assertStringContainsString('буд. 21, кв. 85', $html);
        $this->assertStringContainsString('Друга · 25.10 13:00', $html);
    }

    /**
     * A refused hour is persisted before the validator runs; the journal then flushes, and
     * that flush would write the hour the rules had just refused. The detach is the line
     * between «бот відмовив» and a booking that broke every limit.
     */
    public function testARefusedHourIsDetachedBeforeTheJournalFlushes(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../src/Telegram/SchedulePavilion/Command/SchedulePavilion.php');

        $detach = strpos($src, '$this->em->detach($scheduledSet);');
        $journal = strpos($src, '$this->journal(BookingLog::ACTION_REFUSED');

        $this->assertNotFalse($detach);
        $this->assertNotFalse($journal);
        $this->assertLessThan($journal, $detach);
    }
}
