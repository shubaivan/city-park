<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\GuestPass;
use App\Entity\QrScan;
use App\Entity\TelegramUser;
use App\Service\GuardFeed;
use PHPUnit\Framework\TestCase;

/**
 * What a pass line says without a click (Иван, 22.11.2026), and who leads a personal
 * code's contacts. Database-free: GuardFeed is static on purpose.
 */
class GuardFeedPassSummaryTest extends TestCase
{
    /** @dataProvider labels */
    public function testTheCarIsSplitOffTheLabelOnlyWhenItIsOne(string $label, ?string $plate, string $purpose): void
    {
        $this->assertSame([$plate, $purpose], GuardFeed::splitPlate($label));
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function labels(): iterable
    {
        yield 'latin, glued' => ['AA1234BB доставка пральної машини', 'AA 1234 BB', 'доставка пральної машини'];
        yield 'cyrillic, spaced, dash' => ['СА 1234 АВ — будівельники', 'CA 1234 AB', 'будівельники'];
        yield 'only the plate' => ['KA7777XX', 'KA 7777 XX', ''];
        yield 'a non-standard plate first' => ['AB1234 курʼєр', 'AB1234', 'курʼєр'];
        yield 'no plate' => ['Бригада, ремонт', null, 'Бригада, ремонт'];
        yield 'a word, not a plate' => ['Доставка меблів', null, 'Доставка меблів'];
        yield 'a model is not a plate' => ['Mazda CX5', null, 'Mazda CX5'];
        yield 'a number is not a plate' => ['2 бригади', null, '2 бригади'];
    }

    public function testTheStatusIsNamed(): void
    {
        $now = new \DateTime('2026-11-22 12:00', new \DateTimeZone('Europe/Kyiv'));
        $pass = (new GuestPass())->setLabel('AA1234BB доставка');
        (new \ReflectionProperty(GuestPass::class, 'id'))->setValue($pass, 8);

        $never = GuardFeed::passSummary($pass, $now);
        $this->assertSame('inactive', $never['status']);
        $this->assertSame('⚪️ не активний', $never['status_label']);
        $this->assertSame(8, $never['id']);
        $this->assertSame('AA 1234 BB', $never['plate']);
        $this->assertSame('доставка', $never['purpose']);

        $pass->setActiveUntil(new \DateTime('2026-11-24 23:59', new \DateTimeZone('Europe/Kyiv')));
        $this->assertSame('✅ діє до 24.11 23:59', GuardFeed::passSummary($pass, $now)['status_label']);

        $pass->setActiveUntil(new \DateTime('2026-11-22 11:00', new \DateTimeZone('Europe/Kyiv')));
        $this->assertSame('⚪️ не активний · діяв до 22.11 11:00', GuardFeed::passSummary($pass, $now)['status_label']);

        $pass->setActiveUntil(new \DateTime('2026-11-24 23:59', new \DateTimeZone('Europe/Kyiv')));
        $pass->revoke();
        $this->assertSame('revoked', GuardFeed::passSummary($pass, $now)['status']);
    }

    /** The holder of a personal code leads; the flat's others follow, the holder not repeated. */
    public function testThePersonLeadsAndIsNotRepeated(): void
    {
        $flat = (new Account())->setAccountNumber('2200');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($flat, 12);
        $people = [];
        foreach ([42, 43, 44, 45] as $id) {
            $p = (new TelegramUser())->setAccount($flat);
            (new \ReflectionProperty(TelegramUser::class, 'id'))->setValue($p, $id);
            $people[$id] = $p;
        }
        $scan = (new QrScan())->setKind(QrScan::KIND_RESIDENT)->setSubjectAccount($flat)
            ->setSubjectUser($people[43])->setSubjectUserLabel('x');
        (new \ReflectionProperty(QrScan::class, 'id'))->setValue($scan, 1);

        $item = GuardFeed::withContacts([GuardFeed::fromScan($scan)], [], array_values($people))[0];

        $this->assertSame(43, $item['person_id']);
        $ids = array_map(static fn (TelegramUser $p) => $p->getId(), $item['contacts']);
        $this->assertNotContains(43, $ids);
        $this->assertCount(GuardFeed::MAX_CONTACTS - 1, $ids);
    }
}
