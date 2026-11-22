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
    private function render(array $entries, array $passes = [], string $role = 'ROLE_ADMIN', array $gate = [], bool $current = true, array $allPasses = [], array $people = [], ?int $passFilter = null, ?GuestPass $pass = null, array $counts = [], ?int $personFilter = null, ?\App\Entity\TelegramUser $person = null, ?string $personLabel = null): string
    {
        self::bootKernel();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(new InMemoryUser('alina', null, [$role]), 'main', [$role]),
        );

        $now = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $monday = GuardFeed::weekStart($now);

        return self::getContainer()->get(Environment::class)->render('admin/scans.html.twig', [
            'days' => GuardFeed::byDay(GuardFeed::withContacts(GuardFeed::merge($entries, $gate), $allPasses, $people)),
            'week' => $monday,
            'week_label' => GuardFeed::weekLabel($monday),
            'is_current_week' => $current,
            'prev_week' => '2026-11-09',
            'next_week' => $current ? null : '2026-11-23',
            'cursor' => GuardFeed::cursor($entries, $gate),
            'is_live' => $passFilter !== null || $personFilter !== null || $current,
            'pass_filter' => $passFilter,
            'pass' => $pass,
            'pass_info' => $pass !== null ? GuardFeed::passSummary($pass, $now) : null,
            'person_filter' => $personFilter,
            'person' => $person,
            'person_label' => $personLabel,
            'calendar' => [
                'month' => $now->format('Y-m'),
                'label' => \App\Service\GuardCalendar::monthLabel($now),
                'weeks' => \App\Service\GuardCalendar::grid($now, $counts, $now),
                'prev' => '2026-10',
                'next' => null,
            ],
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

    /**
     * Every pass line in the feed names who issued the pass, with the phone (Иван,
     * 21.11.2026 — «це важливо») — a revoked pass too, which is not in the live list.
     */
    public function testEveryPassLineNamesItsIssuerWithThePhone(): void
    {
        $issuer = (new \App\Entity\TelegramUser())->setFirstName('Ніна')->setLastName('Коваль')->setPhoneNumber('+380 67 111-22-33');
        (new \ReflectionProperty(\App\Entity\TelegramUser::class, 'id'))->setValue($issuer, 5);
        $revoked = (new GuestPass())->setAccount($this->flat(9))->setLabel('Бригада')->setIssuedBy($issuer);
        (new \ReflectionProperty(GuestPass::class, 'id'))->setValue($revoked, 4);

        $scan = self::withId($this->scan(QrScan::KIND_GUEST, QrScan::RESULT_REVOKED));
        $press = self::withId((new GateEvent())->setDirection(GateEvent::DIRECTION_IN)->setKind(QrScan::KIND_GUEST)
            ->setPersonLabel('Бригада')->setPlaceLabel('буд. 19, кв. 9')->setGuestPassId(4));

        $html = $this->render([$scan], [], 'ROLE_ADMIN', [$press], true, [$revoked]);
        $this->assertSame(2, substr_count($html, 'href="tel:+380671112233"'), 'the scan line and the entry line both');
        $this->assertSame(2, substr_count($html, 'href="/admin/users/5"'));
        $this->assertStringContainsString('Видав: ', $html);

        $guard = $this->render([$scan], [], 'ROLE_GUARD', [$press], true, [$revoked]);
        $this->assertSame(2, substr_count($guard, 'href="tel:+380671112233"'), 'the gate rings the issuer too');
        $this->assertStringNotContainsString('/admin/users/', $guard);
        $this->assertStringContainsString('Ніна Коваль', $guard);
    }

    /** A pass the base no longer knows still says «Видав: —» rather than nothing. */
    public function testAPassLineWithoutAKnownIssuerSaysSo(): void
    {
        $html = $this->render([self::withId($this->scan(QrScan::KIND_GUEST, QrScan::RESULT_OK))]);

        $this->assertMatchesRegularExpression('/Видав:\s*—/u', $html);
    }

    /** Every line carries a contact line — an unrecognised code says «—». */
    public function testEveryLineHasAContactEvenWhenThereIsNobody(): void
    {
        $unknown = self::withId($this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_UNKNOWN));
        $html = $this->render([$unknown]);

        $this->assertMatchesRegularExpression('/Контакт:\s*—/u', $html);
    }

    /** A resident line names the people on the flat, with their phones. */
    public function testAResidentLineNamesThePeopleOnTheFlat(): void
    {
        $flat = $this->flat(12);
        $person = (new \App\Entity\TelegramUser())->setFirstName('Олена')->setLastName('Бойко')->setPhoneNumber('380501234567')->setAccount($flat);
        (new \ReflectionProperty(\App\Entity\TelegramUser::class, 'id'))->setValue($person, 77);
        $scan = self::withId($this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_OK)->setSubjectAccount($flat));

        $html = $this->render([$scan], [], 'ROLE_GUARD', [], true, [], [$person]);

        $this->assertStringContainsString('Контакт: ', $html);
        $this->assertStringContainsString('Олена Бойко', $html);
        $this->assertStringContainsString('href="tel:+380501234567"', $html);
        $this->assertStringNotContainsString('/admin/users/', $html);
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
     * «Що це було» leads to the thing it names — the pass's own feed (21.11.2026).
     *
     * It used to scroll to the pass's row in the live list, which left a revoked pass with
     * no link at all: the one whose story is asked for most. Now every pass mark opens
     * `?pass=<id>`, which holds the pass's whole life whether it is live or not.
     */
    public function testEveryPassMarkOpensThePassFeedRevokedToo(): void
    {
        $html = $this->render([
            self::withId($this->scan(QrScan::KIND_GUEST, QrScan::RESULT_OK)),
            self::withId($this->scan(QrScan::KIND_GUEST, QrScan::RESULT_REVOKED)),
        ]);

        $this->assertSame(2, substr_count($html, '<a href="/admin/scans?pass=4#pass-filter" class="pass-num">'));
        $this->assertSame(2, substr_count($html, 'data-href="/admin/scans?pass=4#pass-filter"'), 'the whole line opens the pass too');
        $this->assertStringNotContainsString('href="#pass-', $html);
    }

    /** A live pass's row opens its feed — for the gate's login too. */
    public function testALivePassRowOpensItsFeed(): void
    {
        $pass = (new GuestPass())->setAccount($this->flat(9))->setLabel('Бригада, ремонт');
        (new \ReflectionProperty(GuestPass::class, 'id'))->setValue($pass, 4);

        foreach (['ROLE_ADMIN', 'ROLE_GUARD'] as $role) {
            $html = $this->render([], [$pass], $role);
            $this->assertStringContainsString('data-href="/admin/scans?pass=4#pass-filter"', $html);
            $this->assertStringContainsString('<a href="/admin/scans?pass=4#pass-filter" class="pass-open">Бригада, ремонт</a>', $html);
        }
    }

    /**
     * One pass's page says which pass, how to leave, and polls for that pass only.
     * The guard's login reads it too, still without links into cards.
     */
    public function testThePassPageNamesThePassAndPollsForIt(): void
    {
        $issuer = (new \App\Entity\TelegramUser())->setFirstName('Ніна')->setLastName('Коваль')->setPhoneNumber('380671112233');
        (new \ReflectionProperty(\App\Entity\TelegramUser::class, 'id'))->setValue($issuer, 5);
        $pass = (new GuestPass())->setAccount($this->flat(9))->setLabel('Бригада, ремонт')->setIssuedBy($issuer);
        (new \ReflectionProperty(GuestPass::class, 'id'))->setValue($pass, 4);
        $pass->revoke();

        foreach (['ROLE_ADMIN', 'ROLE_GUARD'] as $role) {
            $html = $this->render([self::withId($this->scan(QrScan::KIND_GUEST, QrScan::RESULT_REVOKED))], [], $role, [], true, [$pass], [], 4, $pass);

            $this->assertStringContainsString('Показано: <b>👷 пропуск №4</b>', $html);
            $this->assertStringContainsString('<b>Бригада, ремонт</b>', $html);
            $this->assertStringContainsString('✕ Усі події', $html);
            $this->assertStringContainsString('скасований', $html);
            $this->assertStringContainsString('href="tel:+380671112233"', $html);
            $this->assertStringContainsString('data-pass="4"', $html);
            $this->assertStringContainsString('data-live="1"', $html, 'a pass is live whatever week it is');
            $this->assertStringContainsString('data-week="pass"', $html);
            $this->assertStringNotContainsString('Попередній тиждень', $html, 'a pass has no weeks');
            if ($role === 'ROLE_GUARD') {
                $this->assertStringNotContainsString('/admin/users/', $html);
                $this->assertStringNotContainsString('/admin/objects/', $html);
            }
        }
    }

    /** A pass the base no longer has still gets its page, saying so. */
    public function testAPassThatIsGoneStillHasAPage(): void
    {
        $html = $this->render([self::withId($this->scan(QrScan::KIND_GUEST, QrScan::RESULT_REVOKED))], [], 'ROLE_GUARD', [], true, [], [], 4, null);

        $this->assertStringContainsString('Пропуск #4', $html);
        $this->assertStringContainsString('✕ Усі події', $html);
    }

    /**
     * Today's cell grows with the 10-second poll (21.11.2026): the cells carry their date and
     * count for the script, and the script applies the calendar's own rule — +1 per new line,
     * −1 on the day of a scan its press replaces. The arithmetic itself was checked in a
     * browser against a reload; this pins that the hooks are there.
     */
    public function testTheCalendarCellsCarryWhatTheLivePollNeeds(): void
    {
        $now = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $today = $now->format('Y-m-d');
        $html = $this->render([], [], 'ROLE_GUARD', [], true, [], [], null, null, [$today => ['in' => 1, 'out' => 0, 'scans' => 2]]);

        $this->assertMatchesRegularExpression('#<td class="lvl-4 today" data-date="' . $today . '" data-total="3">#', $html);
        $this->assertStringContainsString('calBump(row.day, 1);', $html);
        $this->assertStringContainsString('calBump(oldSection.dataset.day, -1);', $html);
    }

    /** The calendar is on the page, counts by day and leads to that day in the feed. */
    public function testTheCalendarLeadsToTheDay(): void
    {
        $now = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $today = $now->format('Y-m-d');
        $html = $this->render([], [], 'ROLE_GUARD', [], true, [], [], null, null, [$today => ['in' => 2, 'out' => 1, 'scans' => 4]]);

        $this->assertStringContainsString('id="calendar"', $html);
        $this->assertMatchesRegularExpression('#href="/admin/scans\?week=' . GuardFeed::weekStart($now)->format('Y-m-d') . '&amp;month=' . $now->format('Y-m') . '\#day-' . $today . '"[^>]*>\s*<span class="dn">' . (int)$now->format('j') . '</span><span class="dc">7</span>#u', $html);
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

    #############
    # Personal codes, pass details, filters (22.11.2026)
    #############

    private function holder(Account $flat): \App\Entity\TelegramUser
    {
        $person = (new \App\Entity\TelegramUser())->setFullName('Олена Петренко')->setPhoneNumber('380501234567')
            ->setRole(\App\Entity\TelegramUser::ROLE_TENANT)->setAccount($flat);
        (new \ReflectionProperty(\App\Entity\TelegramUser::class, 'id'))->setValue($person, 42);

        return $person;
    }

    /** A personal code's line names its holder — name, role, phone — and the name filters the feed. */
    public function testAPersonalCodeLineNamesThePersonAndFiltersByThem(): void
    {
        $flat = $this->flat(12);
        $holder = $this->holder($flat);
        $other = (new \App\Entity\TelegramUser())->setFullName('Петро Петренко')->setPhoneNumber('380671112233')->setAccount($flat);
        (new \ReflectionProperty(\App\Entity\TelegramUser::class, 'id'))->setValue($other, 43);
        $scan = self::withId($this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_OK)->setSubjectAccount($flat)
            ->setSubjectUser($holder)->setSubjectUserLabel('Олена Петренко · Орендар'));

        foreach (['ROLE_ADMIN', 'ROLE_GUARD'] as $role) {
            $html = $this->render([$scan], [], $role, [], true, [], [$holder, $other]);

            $this->assertStringContainsString('<a href="/admin/scans?person=42#person-filter" class="person-open">Олена Петренко</a>', $html);
            $this->assertStringContainsString('data-href="/admin/scans?person=42#person-filter"', $html, 'the whole line filters too');
            $this->assertStringContainsString('Орендар', $html);
            $this->assertStringContainsString('href="tel:+380501234567"', $html);
            $this->assertStringContainsString('Інші з квартири:', $html);
            $this->assertStringContainsString('href="tel:+380671112233"', $html);
            $this->assertSame(1, substr_count($html, 'href="tel:+380501234567"'), 'the holder is not listed twice');
            $this->assertStringNotContainsString('старий код', $html);
            if ($role === 'ROLE_GUARD') {
                $this->assertStringNotContainsString('/admin/users/', $html);
            } else {
                $this->assertStringContainsString('href="/admin/users/42"', $html);
            }
        }
    }

    /** An old flat-level code is still a line — and says the person is not known. */
    public function testAnOldFlatLevelCodeSaysThePersonIsUnknown(): void
    {
        $flat = $this->flat(12);
        $scan = self::withId($this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_OK)->setSubjectAccount($flat));

        $html = $this->render([$scan], [], 'ROLE_GUARD', [], true, [], [$this->holder($flat)]);

        $this->assertStringContainsString('хто саме — невідомо (старий код квартири)', $html);
        $this->assertStringContainsString('Контакт: ', $html);
        $this->assertStringNotContainsString('class="person-open"', $html);
    }

    /** The person gone from the bot: the label the log kept still says who it was. */
    public function testAPersonGoneFromTheBotIsStillNamedByTheLabel(): void
    {
        $scan = self::withId($this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_PERSON_GONE)->setSubjectAccount($this->flat(12))
            ->setSubjectUserLabel('Олена Петренко · Орендар'));

        $html = $this->render([$scan], [], 'ROLE_GUARD');

        $this->assertStringContainsString('Олена Петренко · Орендар', $html);
        $this->assertStringContainsString('відв&#039;язали від квартири', $html);
        $this->assertStringNotContainsString('class="person-open"', $html);
    }

    /** A pass line reads without a click: number, purpose, car, status, uses, issuer. */
    public function testAPassLineCarriesThePassDetails(): void
    {
        $issuer = (new \App\Entity\TelegramUser())->setFullName('Ніна Коваль')->setPhoneNumber('380671112233');
        (new \ReflectionProperty(\App\Entity\TelegramUser::class, 'id'))->setValue($issuer, 5);
        $pass = (new GuestPass())->setAccount($this->flat(9))->setLabel('AA1234BB доставка пральної машини')->setIssuedBy($issuer);
        (new \ReflectionProperty(GuestPass::class, 'id'))->setValue($pass, 4);
        $pass->setActiveUntil(new \DateTime('+2 hours', new \DateTimeZone('Europe/Kyiv')));
        $pass->countScan(new \DateTime());
        $pass->countScan(new \DateTime());

        $scan = self::withId($this->scan(QrScan::KIND_GUEST, QrScan::RESULT_OK));
        $press = self::withId((new GateEvent())->setDirection(GateEvent::DIRECTION_IN)->setKind(QrScan::KIND_GUEST)
            ->setPersonLabel('👷 AA1234BB доставка пральної машини')->setPlaceLabel('буд. 19, кв. 9')->setGuestPassId(4));

        foreach (['ROLE_ADMIN', 'ROLE_GUARD'] as $role) {
            $html = $this->render([$scan], [], $role, [$press], true, [$pass]);

            $this->assertSame(2, substr_count($html, 'class="pass-num">👷 пропуск №4</a>'));
            $this->assertSame(2, substr_count($html, '<b class="pass-purpose">доставка пральної машини</b>'));
            $this->assertSame(2, substr_count($html, '<span class="car-plate">AA 1234 BB</span>'));
            $this->assertSame(2, substr_count($html, 'class="pass-status pass-active">✅ діє до '));
            $this->assertSame(2, substr_count($html, '· сканів: 2'));
            $this->assertSame(2, substr_count($html, 'href="tel:+380671112233"'));
        }

        $pass->revoke();
        $html = $this->render([$scan], [], 'ROLE_GUARD', [], true, [$pass]);
        $this->assertStringContainsString('class="pass-status pass-revoked">🗑 скасований', $html);
    }

    /** A pass the base no longer has: the entry line still names what the log kept. */
    public function testAGonePassStillShowsItsLabelOnAnEntryLine(): void
    {
        $press = self::withId((new GateEvent())->setDirection(GateEvent::DIRECTION_IN)->setKind(QrScan::KIND_GUEST)
            ->setPersonLabel('👷 Бригада плиточників')->setPlaceLabel('буд. 19, кв. 9')->setGuestPassId(4));

        $html = $this->render([], [], 'ROLE_GUARD', [$press]);

        $this->assertStringContainsString('<b class="pass-purpose">Бригада плиточників</b>', $html);
        $this->assertStringContainsString('буд. 19, кв. 9', $html);
    }

    /** One person's page: a visible chip with the way back, the poll and calendar filtered. */
    public function testThePersonPageSaysWhoAndHowToLeave(): void
    {
        $flat = $this->flat(12);
        $holder = $this->holder($flat);
        $scan = self::withId($this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_OK)->setSubjectAccount($flat)
            ->setSubjectUser($holder)->setSubjectUserLabel('Олена Петренко · Орендар'));

        foreach (['ROLE_ADMIN', 'ROLE_GUARD'] as $role) {
            $html = $this->render([$scan], [], $role, [], true, [], [$holder], null, null, [], 42, $holder);

            $this->assertStringContainsString('id="person-filter"', $html);
            $this->assertStringContainsString('Показано: <b>👤 Олена Петренко</b>', $html);
            $this->assertStringContainsString('<a class="ff-clear btn btn-dark" href="/admin/scans">✕ Усі події</a>', $html);
            $this->assertStringContainsString('data-person="42"', $html);
            $this->assertStringContainsString('data-week="person"', $html);
            $this->assertStringContainsString('data-live="1"', $html);
            $this->assertStringContainsString('?person=42&amp;month=', $html, 'the calendar keeps the filter');
            $this->assertStringNotContainsString('Попередній тиждень', $html);
            $this->assertStringNotContainsString('id="pass-filter"', $html);
            if ($role === 'ROLE_GUARD') {
                $this->assertStringNotContainsString('/admin/users/', $html);
            }
        }

        $gone = $this->render([], [], 'ROLE_GUARD', [], true, [], [], null, null, [], 42, null, 'Олена Петренко · Орендар');
        $this->assertStringContainsString('Показано: <b>👤 Олена Петренко · Орендар</b>', $gone);
        $this->assertStringContainsString('У боті цієї людини вже немає', $gone);
    }

    /** The pass chip reads «Показано: пропуск №N» with the way back. */
    public function testThePassPageHasTheChip(): void
    {
        $html = $this->render([], [], 'ROLE_GUARD', [], true, [], [], 4, null);

        $this->assertStringContainsString('Показано: <b>👷 пропуск №4</b>', $html);
        $this->assertStringContainsString('<a class="ff-clear btn btn-dark" href="/admin/scans">✕ Усі події</a>', $html);
    }

    /** The unfiltered feed shows no chip. */
    public function testTheDefaultFeedHasNoChip(): void
    {
        $html = $this->render([]);

        $this->assertStringNotContainsString('class="ff-clear', $html);
        $this->assertStringContainsString('data-person=""', $html);
    }
}
