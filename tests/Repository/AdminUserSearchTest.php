<?php

namespace App\Tests\Repository;

use App\Repository\TelegramUserRepository;
use PHPUnit\Framework\TestCase;

/**
 * The /admin/users search, guarding the two ways it lied to the accountant on 03.09.2026.
 *
 * 1. The table hid everyone not yet linked to a flat, search included. Аліна searched a
 *    phone number, got nothing, and told the man «немає номера в базі» — his row had been
 *    in the table for an hour and a half, unlinked and therefore invisible. He escalated
 *    in the residents' chat and the whole argument was one WHERE clause. The table now
 *    shows everyone; «Підтверджені мешканці» is a filter you press, not a default.
 * 2. Typing the same value into the global "Search:" box and the «Телефон» column threw
 *    "DataTables warning: Ajax error": the bound values went through array_unique(),
 *    which deduplicates by VALUE, so one of two identical values lost its key and the
 *    query reached Doctrine with a placeholder nothing was bound to.
 *
 * Both live in the WHERE builder, which is why it is a separate static method — the rest
 * of getDataTablesData() needs a database, and this needs to run in CI.
 */
class AdminUserSearchTest extends TestCase
{
    private function conditions(array $params, ?string $search = null): string
    {
        [$conditions] = TelegramUserRepository::buildDataTablesFilters($params, $search);

        return implode(' AND ', $conditions);
    }

    /**
     * The regression that started the argument: an unlinked person must be findable by
     * searching for them, without first knowing to press any filter at all.
     */
    public function testAGlobalSearchSpansEveryone(): void
    {
        $this->assertStringNotContainsString(
            'a.id IS NOT NULL',
            $this->conditions([], '380506105465'),
            'a search must reach the unlinked — that is exactly who the accountant cannot find',
        );
    }

    public function testEveryColumnFilterAlsoSpansEveryone(): void
    {
        foreach ([
            'search_phone' => '380506105465',
            'search_last_name' => 'Божко',
            'search_first_name' => 'Іван',
            'search_username' => 'someone',
            'search_address' => 'кв. 86',
            'account_number_filter' => '120086',
        ] as $param => $value) {
            $this->assertStringNotContainsString(
                'a.id IS NOT NULL',
                $this->conditions([$param => $value]),
                sprintf('%s is a lookup, so it must not be restricted to linked residents', $param),
            );
        }
    }

    /**
     * Browsing shows everyone too. Hiding the unlinked by default lasted one day
     * (02–03.09.2026) and is what made «немає номера в базі» possible.
     */
    public function testPlainBrowsingHidesNobody(): void
    {
        $where = $this->conditions([]);

        $this->assertStringNotContainsString('a.id IS NOT NULL', $where);
        $this->assertStringNotContainsString('a.id IS NULL', $where);
    }

    public function testTheUnlinkedFilterShowsOnlyTheUnlinked(): void
    {
        $where = $this->conditions(['status_filter' => 'unlinked']);

        $this->assertStringContainsString('a.id IS NULL', $where);
        $this->assertStringNotContainsString('a.id IS NOT NULL', $where);
    }

    /**
     * Людмила's view, the one the removed default used to give her for free.
     */
    public function testTheLinkedFilterShowsOnlyResidents(): void
    {
        $this->assertStringContainsString(
            'a.id IS NOT NULL',
            $this->conditions(['status_filter' => 'linked']),
        );
    }

    /**
     * A pressed button and a typed search combine — «Підтверджені мешканці» plus a phone
     * means "find this phone among the verified", not one or the other. The JS sends
     * status_filter alongside every search field for exactly this reason.
     */
    public function testAPressedFilterNarrowsTheSearchInsteadOfReplacingIt(): void
    {
        $where = $this->conditions(
            ['status_filter' => 'linked', 'search_phone' => '380506105465'],
        );

        $this->assertStringContainsString('a.id IS NOT NULL', $where);
        $this->assertStringContainsString('ILIKE(b.phone_number, :search_phone)', $where);

        $unlinked = $this->conditions(
            ['status_filter' => 'unlinked'],
            '380506105465',
        );

        $this->assertStringContainsString('a.id IS NULL', $unlinked);
        $this->assertStringContainsString(':var_search', $unlinked);
    }

    /**
     * recordsTotal is the size of the table, not of the current view. DataTables renders
     * it as «filtered from N total entries» — the denominator the filtered count is read
     * against — so no filter may move it, including the two that swap which rows exist.
     */
    public function testTheTotalCountIgnoresEveryFilter(): void
    {
        foreach ([
            ['status_filter' => 'unlinked'],
            ['status_filter' => 'linked'],
            ['status_filter' => 'debt'],
            ['search_phone' => '380506105465'],
        ] as $params) {
            [$conditions] = TelegramUserRepository::buildDataTablesFilters($params, null, true);

            $this->assertSame(
                [],
                $conditions,
                'the total-count query must carry no filter conditions: ' . json_encode($params),
            );
        }
    }

    /**
     * The other Ajax error: clicking a sortable header whose column is not a DQL field.
     * Four of them were live — vote_blocks, role, area, debt_threshold — and each
     * answered 500. Every column offered in the UI must resolve to something orderable,
     * and anything unknown must degrade rather than reach Doctrine as a guess.
     */
    public function testEveryTableColumnIsSafeToSortBy(): void
    {
        $reflection = new \ReflectionMethod(TelegramUserRepository::class, 'getDataTablesData');
        $source = file(
            $reflection->getFileName(),
            FILE_IGNORE_NEW_LINES
        );
        $body = implode("\n", array_slice(
            $source,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));

        // The map lives inline in the query builder; the assertion is that no column
        // rendered by the table is missing from it.
        foreach (\App\Entity\TelegramUser::$dataTableFields as $field) {
            if (in_array($field, ['debt', 'additional_phones', 'action'], true)) {
                // debt has its own NULL-safe ORDER BY branch; the other two are marked
                // unsortable in telegram_users.js.
                continue;
            }

            $this->assertStringContainsString(
                "'" . $field . "' =>",
                $body,
                sprintf('column "%s" is offered for sorting but has no DQL column mapped', $field),
            );
        }

        $this->assertStringContainsString(
            "?? 'b.id'",
            $body,
            'an unknown sort column must fall back, not be interpolated into the DQL',
        );
    }

    /**
     * The Ajax error: the same phone typed into two different inputs. Both placeholders
     * must survive, or Doctrine throws on the unbound one.
     */
    public function testTheSameValueInTwoInputsKeepsBothPlaceholders(): void
    {
        [, $bindParams] = TelegramUserRepository::buildDataTablesFilters(
            ['search_phone' => '380506105465'],
            '380506105465',
        );

        $this->assertArrayHasKey('var_search', $bindParams);
        $this->assertArrayHasKey('search_phone', $bindParams);
        $this->assertSame($bindParams['var_search'], $bindParams['search_phone']);
    }

    /**
     * The general form of the same bug: whatever the filters, every placeholder named in
     * the SQL has to have a value bound to it.
     */
    public function testEveryPlaceholderIsBound(): void
    {
        $params = [
            'search_phone' => '380506105465',
            'search_last_name' => '380506105465',
            'search_first_name' => '380506105465',
            'search_username' => '380506105465',
            'search_address' => '380506105465',
            'account_number_filter' => '380506105465',
            'role_filter' => 'owner',
            'status_filter' => 'debt_blocked',
            '_debt_price_per_meter' => 13.5,
            '_debt_fallback_threshold' => 1300,
        ];

        [$conditions, $bindParams] = TelegramUserRepository::buildDataTablesFilters(
            $params,
            '380506105465',
        );

        preg_match_all('/:(\w+)/', implode(' ', $conditions), $matches);

        foreach (array_unique($matches[1]) as $placeholder) {
            $this->assertArrayHasKey(
                $placeholder,
                $bindParams,
                sprintf(':%s appears in the query with nothing bound to it', $placeholder),
            );
        }
    }

    /**
     * The building, exactly — not the address box.
     *
     * «Адреса» is one ILIKE across street, house and unit, so typing «19» there matches
     * кв. 19 as readily as буд. 19. «Хто в 19-му будинку» is a different question and one
     * of the most common, so it gets a filter that means what it says.
     */
    public function testHouseFilterMatchesTheBuildingAndNotTheFlatNumber(): void
    {
        [$conditions, $params] = TelegramUserRepository::buildDataTablesFilters(['house_filter' => '19']);

        $this->assertContains('a.house_number = :house_filter', $conditions);
        $this->assertSame('19', $params['house_filter']);

        // The address box stays a broad search — the two must not be conflated.
        [$addressConditions] = TelegramUserRepository::buildDataTablesFilters(['search_address' => '19']);
        $this->assertNotContains('a.house_number = :house_filter', $addressConditions);
    }

    /**
     * $total answers "how many rows does this table have at all" — the denominator
     * DataTables prints as «усього в базі N». A filter that moved it would measure nothing.
     */
    public function testHouseFilterDoesNotChangeTheTotal(): void
    {
        [$conditions] = TelegramUserRepository::buildDataTablesFilters(['house_filter' => '19'], null, true);

        $this->assertNotContains('a.house_number = :house_filter', $conditions);
    }
}
