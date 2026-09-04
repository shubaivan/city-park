<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\DebtSnapshot;
use App\Repository\AccountRepository;
use App\Service\DebtBoardService;
use PHPUnit\Framework\TestCase;

/**
 * The debtors' board publishes apartment numbers to the whole house, so the rules that
 * keep it honest are the ones worth pinning down. Each test here corresponds to a way
 * the board could turn from a nudge into an accusation:
 *
 *  - showing it to somebody who is not a resident;
 *  - publishing figures old enough that the "debtor" has since paid;
 *  - leaking anything beyond an apartment number.
 */
class DebtBoardRulesTest extends TestCase
{
    private function account(int $id, string $apartment, string $debt, string $house = '23', ?int $group = null): Account
    {
        $account = (new Account())
            ->setApartmentNumber($apartment)
            ->setAccountNumber('3200' . $apartment)
            ->setHouseNumber($house)
            ->setStreet('Козацька')
            ->setDebt($debt);
        $account->setOwnerGroupId($group);

        $ref = new \ReflectionProperty(Account::class, 'id');
        $ref->setValue($account, $id);

        return $account;
    }

    /**
     * @param Account[] $debtors
     */
    private function service(array $debtors, ?\DateTimeImmutable $importedAt): DebtBoardService
    {
        $repo = $this->createMock(AccountRepository::class);

        $repo->method('lastDebtImportAt')->willReturn($importedAt);
        $repo->method('findDebtors')->willReturnCallback(
            static fn(int $limit = 0) => $limit > 0 ? array_slice($debtors, 0, $limit) : $debtors,
        );
        $repo->method('debtTotals')->willReturn([
            'total' => array_sum(array_map(static fn(Account $a) => (float)$a->getDebt(), $debtors)),
            'debtors' => count($debtors),
        ]);

        // Ungrouped by default: `owner_group_id` is NULL on all but two rows on prod, and
        // an account with no group is a household of one.
        $repo->method('findGroupSiblings')->willReturnCallback(
            static function (Account $account) use ($debtors): array {
                if ($account->getOwnerGroupId() === null) {
                    return [$account];
                }

                $group = array_values(array_filter(
                    $debtors,
                    static fn (Account $a): bool => $a->getOwnerGroupId() === $account->getOwnerGroupId(),
                ));

                $known = array_map(static fn (Account $a): ?int => $a->getId(), $group);

                return in_array($account->getId(), $known, true) ? $group : [$account, ...$group];
            },
        );

        return new DebtBoardService($repo);
    }

    private function freshBoard(): DebtBoardService
    {
        return $this->service(
            [
                $this->account(1, '134', '12269.00'),
                $this->account(2, '89', '6268.00'),
                $this->account(3, '76', '5402.00'),
                $this->account(4, '85', '2732.00'),
                $this->account(5, '59', '2500.00'),
                $this->account(6, '39', '2168.00'),
            ],
            new \DateTimeImmutable('-2 days'),
        );
    }

    public function testUnlinkedVisitorSeesNothingOnTheMenu(): void
    {
        $this->assertSame('', $this->freshBoard()->menuBlock(null));
    }

    public function testUnlinkedVisitorIsRefusedTheReportWithoutSeeingAnyApartment(): void
    {
        $report = $this->freshBoard()->report(null);

        $this->assertStringNotContainsString('134', $report);
        $this->assertStringContainsString('/phone', $report);
    }

    /**
     * There is deliberately no age limit: an earlier version blanked the board after 30
     * days, which was a guess at the accountant's monthly cadence and would have taken
     * the board away every cycle just before the next file arrived. Old figures stay up,
     * dated, until she replaces them.
     */
    public function testOldFiguresStayUpAndStayDated(): void
    {
        $old = $this->service(
            [$this->account(1, '134', '12269.00')],
            new \DateTimeImmutable('-90 days'),
        );

        $asOf = (new \DateTimeImmutable('-90 days'))
            ->setTimezone(new \DateTimeZone('Europe/Kyiv'))
            ->format('d.m.Y');

        $this->assertTrue($old->isAvailable());
        $this->assertStringContainsString('кв. 134', $old->menuBlock($this->account(9, '5', '0')));
        $this->assertStringContainsString($asOf, $old->menuBlock($this->account(9, '5', '0')));
    }

    /**
     * The one case that is not a guess: nothing was ever imported, so there is no date to
     * stamp and no figure to stand behind. Publish nothing.
     */
    public function testNeverImportedPublishesNothing(): void
    {
        $never = $this->service([$this->account(1, '134', '12269.00')], null);

        $this->assertFalse($never->isAvailable());
        $this->assertSame('', $never->menuBlock($this->account(9, '5', '0')));
        $this->assertStringNotContainsString('кв. 134', $never->report($this->account(9, '5', '0')));
    }

    public function testMenuBlockLeadsWithTheHouseTotalAndTheTopThree(): void
    {
        $block = $this->freshBoard()->menuBlock($this->account(9, '5', '0'));

        // 12269 + 6268 + 5402 + 2732 + 2500 + 2168
        $this->assertStringContainsString('31 339 грн', $block);
        $this->assertStringContainsString('🥇 буд. 23, кв. 134', $block);
        $this->assertStringContainsString('🥈 буд. 23, кв. 89', $block);
        $this->assertStringContainsString('🥉 буд. 23, кв. 76', $block);
        $this->assertStringContainsString('4️⃣ буд. 23, кв. 85', $block);
        $this->assertStringContainsString('5️⃣ буд. 23, кв. 59', $block);
        // Sixth place is in the total and the full report, but not on the podium.
        $this->assertStringNotContainsString('кв. 39', $block);
    }

    public function testBoardIsAlwaysDated(): void
    {
        $asOf = (new \DateTimeImmutable('-2 days'))
            ->setTimezone(new \DateTimeZone('Europe/Kyiv'))
            ->format('d.m.Y');

        $this->assertStringContainsString($asOf, $this->freshBoard()->menuBlock($this->account(9, '5', '0')));
        $this->assertStringContainsString($asOf, $this->freshBoard()->report($this->account(9, '5', '0')));
    }

    public function testDebtorSeesTheirOwnStandingAndIsMarkedInTheReport(): void
    {
        $viewer = $this->account(2, '89', '6268.00');

        $this->assertStringContainsString('2 місце', $this->freshBoard()->menuBlock($viewer));
        $this->assertStringContainsString('(це ви)', $this->freshBoard()->report($viewer));
    }

    public function testResidentWithoutDebtIsToldSo(): void
    {
        $this->assertStringContainsString(
            'боргів не має',
            $this->freshBoard()->menuBlock($this->account(9, '5', '0')),
        );
    }

    /**
     * A household is several особові рахунки, and the viewer's line has to cover all of
     * them. Reading only the object the person happens to be *linked* to answered «боргів
     * не має» to an owner whose own комірчина was on the list two lines below.
     */
    public function testAnOwnerIsToldAboutEveryObjectOfTheirHousehold(): void
    {
        $flat = $this->account(4, '85', '2732.00', '23', 40);
        $storage = $this->account(40, '168', '3000.00', '23', 40)
            ->setUnitType(Account::UNIT_STORAGE);

        $board = $this->service(
            [
                $this->account(1, '134', '12269.00'),
                $storage,
                $flat,
                $this->account(5, '59', '2500.00'),
            ],
            new \DateTimeImmutable('-1 day'),
        );

        $menu = $board->menuBlock($flat);

        $this->assertStringContainsString('кв. 85', $menu);
        $this->assertStringContainsString('комірчина 168', $menu);
        $this->assertStringNotContainsString('боргів не має', $menu);
    }

    /** Both objects of the household are marked in the full list, not just the linked one. */
    public function testEveryObjectOfTheHouseholdIsMarkedInTheReport(): void
    {
        $flat = $this->account(4, '85', '2732.00', '23', 40);
        $storage = $this->account(40, '168', '3000.00', '23', 40);

        $board = $this->service([$storage, $flat], new \DateTimeImmutable('-1 day'));

        $this->assertSame(2, substr_count($board->report($flat), '(це ви)'));
    }

    /**
     * The group is matched on an explicit `owner_group_id`, never on a bare id: an
     * ungrouped account whose id happens to equal somebody else's group number must not
     * be marked as theirs.
     */
    public function testAnUngroupedAccountIsNeverMistakenForAnotherHousehold(): void
    {
        $stranger = $this->account(40, '168', '3000.00', '23');
        $viewer = $this->account(4, '85', '2732.00', '23', 40);

        $board = $this->service([$stranger, $viewer], new \DateTimeImmutable('-1 day'));

        $this->assertSame(1, substr_count($board->report($viewer), '(це ви)'));
    }

    /**
     * The «Моя квартира» jump has to work for the object that actually owes. An owner
     * whose flat is clear and whose паркомісце is not used to get no button at all.
     */
    public function testTheJumpFindsTheObjectThatOwesEvenWhenTheLinkedOneIsClear(): void
    {
        $flat = $this->account(4, '85', '0', '23', 40);
        $parking = $this->account(40, '138', '1500.00', '23', 40)
            ->setUnitType(Account::UNIT_PARKING);

        $board = $this->service(
            [...array_map(fn (int $i): Account => $this->account(100 + $i, (string)(200 + $i), '9000.00'), range(1, 20)), $parking, $flat],
            new \DateTimeImmutable('-1 day'),
        );

        $this->assertSame(2, $board->pageOfViewer($flat));
    }

    public function testReportListsEveryDebtorLargestFirst(): void
    {
        $report = $this->freshBoard()->report($this->account(9, '5', '0'));

        $this->assertSame(
            ['134', '89', '76', '85', '59', '39'],
            $this->orderOf($report, ['134', '89', '76', '85', '59', '39']),
        );
        $this->assertStringContainsString('6 квартир', $report);
    }

    private function snapshot(float $total, int $debtors, string $takenAt): DebtSnapshot
    {
        $snapshot = (new DebtSnapshot())->setTotal($total)->setDebtors($debtors);

        $taken = new \ReflectionProperty(DebtSnapshot::class, 'taken_at');
        $taken->setValue($snapshot, new \DateTimeImmutable($takenAt));

        return $snapshot;
    }

    /**
     * Twenty named, not ten (widened 04.09.2026).
     *
     * The chat post is the only *push* half of the board — it reaches all 77 members
     * whether or not they ever open the bot — and against 149 flats owing money a top ten
     * is a list of the extremes rather than a picture of the house.
     */
    public function testChatAnnouncementLeadsWithFiguresAndNamesTwenty(): void
    {
        $debtors = [];
        for ($i = 1; $i <= 25; $i++) {
            $debtors[] = $this->account($i, (string)(100 + $i), (string)(1000 - $i * 10));
        }

        $board = $this->service($debtors, new \DateTimeImmutable('-1 day'));
        $post = $board->chatAnnouncement(
            $this->snapshot(11_340, 25, '-1 day'),
            $this->snapshot(15_540, 28, '-31 days'),
        );

        $this->assertStringContainsString('Борг мешканців: <b>11 340 грн</b>', $post);
        $this->assertStringContainsString('Квартир з боргом: <b>25</b>', $post);
        $this->assertStringContainsString('зменшився на 4 200 грн', $post);

        // Exactly twenty named: the twentieth is in, the twenty-first is not.
        $this->assertStringContainsString('буд. 23, кв. 120', $post);
        $this->assertStringNotContainsString('кв. 121', $post);
        $this->assertSame(DebtBoardService::ANNOUNCE_SIZE, 20);

        // Telegram refuses anything over 4096 characters outright, and this post is now
        // twice as long as it was designed to be.
        $this->assertLessThan(4096, mb_strlen($post));
    }

    /**
     * The heading counts what is actually printed. «Двадцятка» over twelve names is the
     * kind of small lie that makes people distrust the figures above it.
     */
    public function testTheAnnouncementHeadingCountsTheNamesItPrints(): void
    {
        $debtors = [];
        for ($i = 1; $i <= 12; $i++) {
            $debtors[] = $this->account($i, (string)(100 + $i), (string)(1000 - $i * 10));
        }

        $post = $this->service($debtors, new \DateTimeImmutable('-1 day'))->chatAnnouncement(
            $this->snapshot(11_340, 12, '-1 day'),
            null,
        );

        $this->assertStringContainsString('12 «лідерів»', $post);
    }

    public function testChatAnnouncementReportsAGrowingDebtToo(): void
    {
        $board = $this->service([$this->account(1, '134', '12269.00')], new \DateTimeImmutable('-1 day'));

        $post = $board->chatAnnouncement(
            $this->snapshot(12_269, 1, '-1 day'),
            $this->snapshot(10_269, 1, '-31 days'),
        );

        $this->assertStringContainsString('зріс на 2 000 грн', $post);
    }

    public function testChatAnnouncementCarriesTheDate(): void
    {
        $board = $this->service([$this->account(1, '134', '12269.00')], new \DateTimeImmutable('-1 day'));
        $asOf = (new \DateTimeImmutable('-1 day'))->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('d.m.Y');

        $this->assertStringContainsString(
            $asOf,
            $board->chatAnnouncement($this->snapshot(12_269, 1, '-1 day'), null),
        );
    }

    /**
     * The ЖК is five buildings on one street and apartment numbers repeat across them.
     * Naming a debtor by apartment alone points at every family with that number in the
     * whole complex — on prod, "кв. 76" was one household owing 5 402 грн and another
     * owing 651. The building has to appear on every line.
     */
    public function testRepeatedApartmentNumbersAreSeparatedByBuilding(): void
    {
        $board = $this->service(
            [
                $this->account(1, '76', '5401.85', house: '23'),
                $this->account(2, '76', '651.00', house: '17'),
            ],
            new \DateTimeImmutable('-1 day'),
        );

        $report = $board->report($this->account(9, '5', '0'));

        $this->assertStringContainsString('буд. 23, кв. 76 — <b>5 402 грн</b>', $report);
        $this->assertStringContainsString('буд. 17, кв. 76 — <b>651 грн</b>', $report);
    }

    /**
     * @param string[] $apartments
     * @return string[]
     */
    private function orderOf(string $report, array $apartments): array
    {
        // Pairs, not a keyed map: PHP turns the numeric-looking apartment keys into
        // ints and the comparison against the expected strings then fails on type.
        $positions = array_map(
            static fn(string $apartment) => [$apartment, mb_strpos($report, 'кв. ' . $apartment)],
            $apartments,
        );
        usort($positions, static fn(array $a, array $b) => $a[1] <=> $b[1]);

        return array_map(static fn(array $pair) => $pair[0], $positions);
    }

    #############
    # Paging the in-bot report
    #############

    /**
     * @return Account[] 40 debtors, largest first
     */
    private function manyDebtors(): array
    {
        $debtors = [];

        for ($i = 1; $i <= 40; $i++) {
            $debtors[] = $this->account($i, (string)(100 + $i), (string)(10000 - $i * 100));
        }

        return $debtors;
    }

    /**
     * The report used to fill one message and stop with «показано перших N із M», which on
     * 149 flats published the top forty and hid everyone below — the wrong forty, since the
     * extremes are already on the menu's podium and the neighbour a resident is actually
     * wondering about is somewhere in the middle.
     */
    public function testTheReportIsPagedRatherThanTruncated(): void
    {
        $debtors = $this->manyDebtors();
        $board = $this->service($debtors, new \DateTimeImmutable('-1 day'));
        $viewer = $debtors[0];

        $this->assertSame(3, $board->pageCount(), '40 debtors at 15 a page');

        $first = $board->report($viewer, 1);
        $this->assertStringContainsString('кв. 101', $first);
        $this->assertStringContainsString('кв. 115', $first);
        $this->assertStringNotContainsString('кв. 116', $first);
        $this->assertStringContainsString('Сторінка 1 з 3', $first);

        $last = $board->report($viewer, 3);
        $this->assertStringContainsString('кв. 140', $last);
        $this->assertStringNotContainsString('кв. 130 —', $last);

        // Every page carries the totals, so no page can be forwarded as "the debt".
        foreach ([1, 2, 3] as $page) {
            $this->assertStringContainsString('Разом:', $board->report($viewer, $page));
        }
    }

    public function testPageNumberIsClampedRatherThanTrusted(): void
    {
        $debtors = $this->manyDebtors();
        $board = $this->service($debtors, new \DateTimeImmutable('-1 day'));

        // A stale callback from an older, longer list must not answer with an empty page.
        $this->assertStringContainsString('Сторінка 3 з 3', $board->report($debtors[0], 99));
        $this->assertStringContainsString('Сторінка 1 з 3', $board->report($debtors[0], 0));
        $this->assertStringContainsString('Сторінка 1 з 3', $board->report($debtors[0], -5));
    }

    /**
     * "Ви 63-й" is useless if reaching that line means tapping ➡️ four times.
     */
    public function testTheViewerCanBePointedAtTheirOwnPage(): void
    {
        $debtors = $this->manyDebtors();
        $board = $this->service($debtors, new \DateTimeImmutable('-1 day'));

        $this->assertSame(1, $board->pageOfViewer($debtors[0]));
        $this->assertSame(2, $board->pageOfViewer($debtors[15]));
        $this->assertSame(3, $board->pageOfViewer($debtors[39]));

        // Somebody who owes nothing has no page, and must not be sent hunting for one.
        $this->assertNull($board->pageOfViewer($this->account(999, '77', '0')));
        $this->assertNull($board->pageOfViewer(null));
    }

    /**
     * The viewer's own line rides on every page — "am I on this list?" is the first
     * question anyone opens the report with, and leafing through ten pages to answer it
     * is how a nudge becomes an annoyance.
     */
    public function testEveryPageTellsTheViewerWhereTheyStand(): void
    {
        $debtors = $this->manyDebtors();
        $board = $this->service($debtors, new \DateTimeImmutable('-1 day'));

        foreach ([1, 2, 3] as $page) {
            $this->assertStringContainsString(
                'Ваша квартира у списку',
                $board->report($debtors[20], $page),
            );
        }

        $this->assertStringContainsString(
            'боргів не має',
            $board->report($this->account(999, '77', '0'), 1),
        );
    }

    /** No page may ever be long enough for Telegram to refuse it. */
    public function testNoPageApproachesTheTelegramMessageLimit(): void
    {
        $debtors = $this->manyDebtors();
        $board = $this->service($debtors, new \DateTimeImmutable('-1 day'));

        foreach (range(1, $board->pageCount()) as $page) {
            $this->assertLessThan(4096, mb_strlen($board->report($debtors[0], $page)));
        }
    }

    /**
     * A parking space is not a flat, and the board says so.
     *
     * Six of the eight non-flat accounts on prod carry a bare number in
     * `apartment_number` — the two spelled-out ones ("Паркінг 138") were the only reason
     * the old "bare number ⇒ кв." rule looked correct. It published `237191`, a parking
     * space owing 1 330 грн, as «буд. 19, кв. 191». No flat with that number exists in
     * that building today, which is the only thing that kept it from accusing somebody;
     * the day the accountant adds one, this is the «кв. 76 is two households» case the
     * building rule was written for, with the board doing the accusing.
     */
    public function testNonFlatUnitsAreNotPublishedAsApartments(): void
    {
        $parking = (new Account())
            ->setAccountNumber('237191')
            ->setApartmentNumber('191')
            ->setHouseNumber('19')
            ->setStreet('Козацька')
            ->setDebt('1330.00');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($parking, 175);

        $storage = (new Account())
            ->setAccountNumber('235169')
            ->setApartmentNumber('169')
            ->setHouseNumber('19')
            ->setStreet('Козацька')
            ->setDebt('142.00');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($storage, 108);

        $this->assertTrue($parking->isParking());
        $this->assertTrue($storage->isStorage());

        $report = $this->service([$parking, $storage], new \DateTimeImmutable('-1 day'))
            ->report($parking);

        $this->assertStringContainsString('буд. 19, паркомісце 191', $report);
        $this->assertStringContainsString('буд. 19, комірчина 169', $report);
        $this->assertStringNotContainsString('кв. 191', $report);
        $this->assertStringNotContainsString('кв. 169', $report);
    }

    /** A flat is still a flat, and the building is still never dropped. */
    public function testFlatsKeepTheirWordingAndTheirBuilding(): void
    {
        $report = $this->freshBoard()->report($this->account(1, '134', '12269.00'));

        $this->assertStringContainsString('буд. 23, кв. 134', $report);
    }

    /**
     * «буд. 19, кв. 24 — 0 грн» in 149th place, seen on prod 04.09.2026.
     *
     * The account owed 0.25 грн — a remainder in the accountant's books, not arrears — and
     * the board rounds to the hryvnia. Naming a household to the whole house for
     * twenty-five kopecks is the accusation these rules exist to avoid, and "owes 0" reads
     * as a bug on top of it. Sub-hryvnia amounts no longer reach the list; money() is the
     * second guard, so nothing anywhere prints a debtor owing nothing.
     */
    public function testNothingIsEverPublishedAsOwingZero(): void
    {
        $board = $this->service(
            [$this->account(1, '134', '12269.00'), $this->account(2, '24', '0.25')],
            new \DateTimeImmutable('-1 day'),
        );

        // The service is fed by the repository, which now filters these out — but the
        // renderer must not be able to print «0 грн» even if one reaches it.
        $report = $board->report($this->account(1, '134', '12269.00'));

        $this->assertStringNotContainsString('— <b>0 грн</b>', $report);
    }
}
