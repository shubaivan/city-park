<?php

namespace App\Tests\Controller;

use App\Entity\TelegramUser;
use PHPUnit\Framework\TestCase;

/**
 * The /admin/users table draws its columns by **index**, and that is a trap.
 *
 * Every `columnDef` in `telegram_users.js` targets a number, not a name, so a column
 * appended anywhere but the end silently repaints the wrong cells — the debt renderer
 * onto area, area onto the threshold. CLAUDE.md says so in words; this says so in a way
 * that fails.
 *
 * The specific thing pinned here: `role` is the third column appended after the shared
 * `$dataTableFields`, which puts it at index 18 — and that is the index the JS hides,
 * because the role is now drawn beside the name instead («Ваня, власник»).
 */
class UsersTableColumnsTest extends TestCase
{
    /** The order AdminController appends after TelegramUser::$dataTableFields. */
    private const APPENDED = ['action', 'vote_blocks', 'role', 'full_name'];

    private function js(): string
    {
        return file_get_contents(__DIR__ . '/../../assets/js/telegram_users.js');
    }

    private function controller(): string
    {
        return file_get_contents(__DIR__ . '/../../src/Controller/AdminController.php');
    }

    /**
     * The controller still appends the extra columns in the order the JS is written
     * against. Inserting one before `role` moves it off index 18 and un-hides a column
     * that now duplicates the name cell.
     */
    public function testTheAppendedColumnsKeepTheirOrder(): void
    {
        $source = $this->controller();
        $positions = [];

        foreach (self::APPENDED as $field) {
            $needle = sprintf('$fieldNames[] = \'%s\';', $field);
            $at = strpos($source, $needle);

            $this->assertNotFalse($at, sprintf('AdminController must still append «%s»', $field));
            $positions[$field] = $at;
        }

        $sorted = $positions;
        asort($sorted);
        $this->assertSame(
            array_keys($positions),
            array_keys($sorted),
            'a column appended out of order shifts every index the JS targets',
        );
    }

    /** Role sits where the JS thinks it does. */
    public function testRoleIsColumnEighteen(): void
    {
        $index = count(TelegramUser::$dataTableFields) + array_search('role', self::APPENDED, true);

        $this->assertSame(18, $index, 'telegram_users.js hides target 18 — keep them in step');
    }

    /**
     * The role column is hidden, not deleted — the documented way to retire one here.
     * Deleting it from the field list would shift `full_name` and repaint the wrong cells.
     */
    public function testTheRoleColumnIsHiddenRatherThanRemoved(): void
    {
        $js = $this->js();

        $this->assertMatchesRegularExpression(
            '/"targets":\s*18,\s*"visible":\s*false/',
            $js,
            'column 18 (role) must be hidden, since it is now drawn inside the name cell',
        );
        $this->assertStringContainsString(
            'role',
            $js,
            'the name renderer reads row.role',
        );
    }

    /** The name cell really does draw the role beside the name. */
    public function testTheNameCellCarriesTheRole(): void
    {
        $js = $this->js();

        $this->assertStringContainsString('var role = row.role', $js);
        $this->assertStringContainsString('roleSuffix', $js);
        $this->assertStringContainsString("role !== '—'", $js, 'an empty role must not print a bare comma');
    }
}
