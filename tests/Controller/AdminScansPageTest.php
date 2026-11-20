<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\GateEvent;
use App\Entity\GuestPass;
use App\Entity\QrScan;
use App\Service\GuardFeed;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

/**
 * The scan log, rendered.
 *
 * It is the page opened *after* something happened in the yard, so the two things it must
 * never do are draw a result as the wrong one and leave a live pass out. Both have
 * precedent: /admin/links spent a week rendering votes as complaints because its template
 * ended in an unguarded `else`.
 */
class AdminScansPageTest extends KernelTestCase
{
    private function render(array $entries, array $passes = [], string $role = 'ROLE_ADMIN', array $gate = [], bool $current = true): string
    {
        self::bootKernel();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(new InMemoryUser('alina', null, [$role]), 'main', [$role]),
        );

        $now = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $monday = GuardFeed::weekStart($now);

        return self::getContainer()->get(Environment::class)->render('admin/scans.html.twig', [
            'days' => GuardFeed::byDay(GuardFeed::merge($entries, $gate)),
            'week' => $monday,
            'week_label' => GuardFeed::weekLabel($monday),
            'is_current_week' => $current,
            'prev_week' => '2026-11-09',
            'next_week' => $current ? null : '2026-11-23',
            'cursor' => GuardFeed::cursor($entries, $gate),
            'passes' => $passes,
            'now' => $now,
        ]);
    }

    private function scan(string $kind, string $result): QrScan
    {
        return (new QrScan())
            ->setKind($kind)
            ->setResult($result)
            ->setScannedByLabel('Ніка (буд. 19, кв. 85)')
            ->setSubjectLabel('буд. 19, кв. 12')
            ->setGuestPassId($kind === QrScan::KIND_GUEST ? 4 : null);
    }

    private static int $nextId = 1;

    private static function withId(object $entity, string $at = 'now'): object
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, self::$nextId++);
        (new \ReflectionProperty($entity::class, 'created_at'))->setValue($entity, new \DateTime($at, new \DateTimeZone('Europe/Kyiv')));

        return $entity;
    }

    public function testItNamesWhoScannedWhom(): void
    {
        $html = $this->render([self::withId($this->scan(QrScan::KIND_GUEST, QrScan::RESULT_OK))]);

        $this->assertStringContainsString('Ніка (буд. 19, кв. 85)', $html);
        $this->assertStringContainsString('буд. 19, кв. 12', $html);
        $this->assertStringContainsString('пропуск', $html);
        $this->assertStringContainsString('class="mark mark-scan"', $html, 'a scan with no press is neither in nor out');
    }

    /**
     * The альтанка is not the guard's business (Иван, 20.11.2026): «без броні» and
     * «дійсний» are gone. What stays is a code that did not check out.
     */
    public function testTheBookingAnswerIsGoneAndProblemsStay(): void
    {
        $html = $this->render([
            self::withId($this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_NO_BOOKING)),
            self::withId($this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_OK)),
        ]);

        $this->assertStringNotContainsString('без броні', $html);
        $this->assertStringNotContainsString('дійсний', $html);
        $this->assertStringNotContainsString('Відповідь', $html);

        $this->assertStringContainsString('пропуск скасований', $this->render([self::withId($this->scan(QrScan::KIND_GUEST, QrScan::RESULT_REVOKED))]));
        $this->assertStringContainsString('код не розпізнано', $this->render([self::withId($this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_UNKNOWN))]));
    }

    /** The live passes are on the page: «які пропуски зараз ходять по ЖК». */
    public function testLivePassesAreListedWithTheirDay(): void
    {
        $account = (new Account())
            ->setAccountNumber('220085')
            ->setApartmentNumber('85')
            ->setHouseNumber('19')
            ->setStreet('Козацька');

        $pass = (new GuestPass())->setAccount($account)->setLabel('Бригада, ремонт');
        $pass->setActiveUntil(new \DateTime('+2 hours', new \DateTimeZone('Europe/Kyiv')));

        $html = $this->render([], [$pass]);

        $this->assertStringContainsString('Бригада, ремонт', $html);
        $this->assertStringContainsString('кв. 85', $html);
        $this->assertStringContainsString('активний до', $html);
    }

    /**
     * When the pass was made: a pass issued this morning and one that has been reused for
     * weeks look the same otherwise, and «Діє до» only says today's window.
     */
    /**
     * The issuer's phone beside the name, for everybody who reads the page (Иван,
     * 12.11.2026): one view, no role rule.
     */
    public function testTheIssuersPhoneIsShownBesideTheName(): void
    {
        $issuer = (new \App\Entity\TelegramUser())->setFirstName('Ніна')->setLastName('Коваль')->setPhoneNumber('380671112233');
        (new \ReflectionProperty(\App\Entity\TelegramUser::class, 'id'))->setValue($issuer, 5);
        $pass = (new GuestPass())->setAccount($this->flat(9))->setLabel('Бригада')->setIssuedBy($issuer);

        $this->assertStringContainsString('href="tel:+380671112233"', $this->render([], [$pass]));
        $this->assertStringContainsString('href="tel:+380671112233"', $this->render([], [$pass], 'ROLE_COMPLAINTS'));
        $this->assertStringContainsString('Ніна Коваль', $this->render([], [$pass]));
    }

    public function testLivePassesShowWhenTheyWereCreated(): void
    {
        $pass = (new GuestPass())->setAccount($this->flat(9))->setLabel('Бригада, ремонт');
        (new \ReflectionProperty(GuestPass::class, 'created_at'))
            ->setValue($pass, new \DateTime('2026-11-12 10:58:00', new \DateTimeZone('Europe/Kyiv')));

        $html = $this->render([], [$pass]);

        $this->assertStringContainsString('<th>Створено</th>', $html);
        $this->assertStringContainsString('12.11 10:58', $html);
    }

    /** «Видав» opens the resident's card and «Квартира» the object's — one click to who and where. */
    public function testIssuerAndFlatLinkToTheirCards(): void
    {
        $issuer = (new \App\Entity\TelegramUser())->setFirstName('Тоха')->setLastName('');
        (new \ReflectionProperty(\App\Entity\TelegramUser::class, 'id'))->setValue($issuer, 533);

        $pass = (new GuestPass())->setAccount($this->flat(503))->setLabel('Бригада, ремонт');
        (new \ReflectionProperty(GuestPass::class, 'issuedBy'))->setValue($pass, $issuer);

        $html = $this->render([], [$pass]);

        $this->assertStringContainsString('href="/admin/users/533"', $html);
        $this->assertStringContainsString('href="/admin/objects/503"', $html);
    }

    /**
     * «Що це було» leads to the thing it names.
     *
     * The cell said «👷 пропуск #2» and did nothing, on a page whose whole job is being
     * opened after the fact: the pass is three lines up in a list of near-identical rows,
     * and finding it by eye is what the anchor removes. The flash is the `:target` rule in
     * base.html.twig, shared with /admin/links.
     */
    public function testThePassLinkFindsItsRowUpThePage(): void
    {
        $pass = (new GuestPass())->setAccount($this->flat(9))->setLabel('Бригада, ремонт');
        (new \ReflectionProperty(GuestPass::class, 'id'))->setValue($pass, 4);

        $html = $this->render([self::withId($this->scan(QrScan::KIND_GUEST, QrScan::RESULT_OK))], [$pass]);

        $this->assertStringContainsString('href="#pass-4"', $html);
        $this->assertStringContainsString('id="pass-4"', $html, 'the anchor must land on a row that is on the page');
    }

    /**
     * A pass that is no longer listed is not linked to.
     *
     * Revoked passes are left out of the table above, so the anchor would scroll nowhere —
     * and a dead link in a security log reads as the record having been lost, which is the
     * one thing this page must never suggest.
     */
    public function testAScanOfAPassThatIsGoneIsNotALink(): void
    {
        $html = $this->render([self::withId($this->scan(QrScan::KIND_GUEST, QrScan::RESULT_REVOKED))]);

        $this->assertStringContainsString('пропуск', $html);
        $this->assertStringNotContainsString('href="#pass-', $html);
    }

    /** A resident scan opens the flat whose code it was. */
    public function testAResidentScanOpensTheObjectCard(): void
    {
        $scan = self::withId($this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_NO_BOOKING)
            ->setSubjectAccount($this->flat(12)));

        $this->assertStringContainsString('/admin/objects/12', $this->render([$scan]));
    }

    /** …and says the same words with no link when the account is gone (SET NULL). */
    public function testAResidentScanWithoutAnAccountIsStillNamed(): void
    {
        $html = $this->render([self::withId($this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_NO_BOOKING))]);

        $this->assertStringContainsString('мешканець', $html);
        $this->assertStringNotContainsString('/admin/objects/', $html);
    }

    private function flat(int $id): Account
    {
        $account = (new Account())
            ->setAccountNumber('2200' . $id)
            ->setApartmentNumber((string)$id)
            ->setHouseNumber('19')
            ->setStreet('Козацька');

        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }

    public function testAnEmptyLogStillRenders(): void
    {
        $html = $this->render([]);

        $this->assertStringContainsString('сканування QR', $html);
        $this->assertStringContainsString('Цього тижня ще нічого не було', $html);
    }

    /**
     * Entries, exits and plain scans in one list, newest first, by day — read by the gate's
     * own login as well as the panel's (Иван, 20.11.2026).
     */
    public function testEntriesExitsAndScansAreInterleavedByDay(): void
    {
        $in = self::withId((new GateEvent())
            ->setDirection(GateEvent::DIRECTION_IN)
            ->setPersonLabel('Олена Петренко')
            ->setPlaceLabel('буд. 19, кв. 85')
            ->setCarLabel('CA 1234 AB')
            ->setGuardLabel('Охорона · Охрана Ситипарк'), 'today 00:10');
        $out = self::withId((new GateEvent())
            ->setDirection(GateEvent::DIRECTION_OUT)
            ->setPersonLabel('👷 AA1234BB Бригада')
            ->setPlaceLabel('буд. 21, кв. 3'), 'today 00:30');
        $scan = self::withId($this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_OK), 'today 00:20');

        foreach (['ROLE_ADMIN', 'ROLE_GUARD'] as $role) {
            $html = $this->render([$scan], [], $role, [$out, $in]);
            $feed = substr($html, (int)strpos($html, 'id="feed"'));

            $this->assertStringContainsString(htmlspecialchars(GuardFeed::dayLabel(new \DateTime('today')), ENT_QUOTES), $html);
            $this->assertStringContainsString('Олена Петренко', $html);
            $this->assertStringContainsString('CA 1234 AB', $html);
            $this->assertStringContainsString('AA1234BB Бригада', $html);
            $this->assertMatchesRegularExpression('/mark mark-out.*mark mark-scan.*mark mark-in/s', $feed, 'newest first: out, scan, in');
            $this->assertStringContainsString('<span class="c-in">1</span>', $html);
            $this->assertStringContainsString('<span class="c-out">1</span>', $html);
        }
    }

    /** The guard's login gets no links into pages it is refused at. */
    public function testTheGuardGetsNoObjectLinks(): void
    {
        $scan = self::withId($this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_OK)->setSubjectAccount($this->flat(12)));

        $this->assertStringNotContainsString('/admin/objects/12', $this->render([$scan], [], 'ROLE_GUARD'));
    }

    /** Only the current week polls; an earlier one is history and offers the way forward. */
    public function testOnlyTheCurrentWeekPolls(): void
    {
        $current = $this->render([]);
        $this->assertStringContainsString('data-live="1"', $current);
        $this->assertStringContainsString('/admin/scans/feed', $current);
        $this->assertStringContainsString('Попередній тиждень', $current);
        $this->assertStringNotContainsString('Наступний тиждень', $current);

        $past = $this->render([], [], 'ROLE_ADMIN', [], false);
        $this->assertStringContainsString('data-live="0"', $past);
        $this->assertStringContainsString('Наступний тиждень', $past);
        $this->assertStringNotContainsString('<template id="feed-day-template"', $past);
    }
}
