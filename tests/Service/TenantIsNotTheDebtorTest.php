<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Service\DebtBoardService;
use App\Telegram\Start\Command\StartCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A tenant is linked to the flat so the bot knows where they live. That is not the same as
 * owing what the flat owes.
 *
 * The link is what admits them to the house chat, lets them book the альтанка and lets them
 * report a broken lift — all correct. Until 08.09.2026 it also made the bot address them as
 * the debtor: the figure sat beside their особовий рахунок on every /start, the board marked
 * it «📌 Ваша квартира», and the monthly reminder told them to pay.
 *
 * **What is deliberately NOT hidden is the board itself.** It names every flat in the house
 * to every resident — their neighbour on the fifth floor reads the same line — so hiding it
 * would leave a tenant less informed than anybody else while protecting nothing. What is
 * switched off is the bot claiming the debt is theirs.
 *
 * `role` is descriptive everywhere else in this bot. This is the one thing it decides, and
 * it is easy to "tidy away" later by somebody making the roles consistent again.
 */
class TenantIsNotTheDebtorTest extends KernelTestCase
{
    private function person(?string $role): TelegramUser
    {
        $user = new TelegramUser();
        $user->setFirstName('Хтось');

        if ($role !== null) {
            $user->setRole($role);
        }

        return $user;
    }

    public function testOnlyATenantIsExcusedFromTheFlatsDebt(): void
    {
        $this->assertFalse($this->person(TelegramUser::ROLE_TENANT)->owesForTheFlat());

        $this->assertTrue($this->person(TelegramUser::ROLE_OWNER)->owesForTheFlat());
        $this->assertTrue($this->person(TelegramUser::ROLE_FAMILY)->owesForTheFlat());

        // NULL is «не вказано», which covers most of прод — and the accountant has not said
        // these people are tenants. Treating an unlabelled resident as one would quietly
        // stop the debt reaching most of the house.
        $this->assertTrue($this->person(null)->owesForTheFlat());
    }

    /**
     * The header's per-object figures are the most personal statement the bot makes about
     * money: they sit beside «Ваш особовий рахунок».
     */
    public function testTheHeaderDoesNotBillSomebodyWhoOnlyRents(): void
    {
        $account = (new Account())
            ->setAccountNumber('230085')
            ->setApartmentNumber('85')
            ->setHouseNumber('19')
            ->setStreet('Козацька');
        $account->setDebt('3416.00');

        $second = (new Account())
            ->setAccountNumber('235168')
            ->setApartmentNumber('168')
            ->setHouseNumber('19')
            ->setStreet('Козацька');
        $second->setDebt('209.00');

        $billed = StartCommand::renderHeader([$account, $second], withDebt: true);
        $notBilled = StartCommand::renderHeader([$account, $second], withDebt: false);

        $this->assertStringContainsString('3 416', $billed);
        $this->assertStringNotContainsString('3 416', $notBilled);
    }

    /**
     * The list still renders for a tenant — it is public to the house — but nothing in it
     * points at them.
     *
     * Pinned on the source rather than on a rendered board: every path through report() and
     * menuBlock() needs a database, and this suite has none. What must hold is that each
     * «це ви» mark and each viewer line is gated, and that is exactly what is easy to lose
     * to somebody adding a third one later.
     */
    public function testEveryClaimOnTheBoardIsGated(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../src/Service/DebtBoardService.php');

        $this->assertSame(
            2,
            substr_count($source, '$ownsTheDebt && $this->isViewer'),
            'both «📌 (це ви)» marks — the podium and the full list — must be gated',
        );

        $this->assertSame(
            2,
            substr_count($source, 'if ($ownsTheDebt) {'),
            'both viewer lines — the menu block and the report footer — must be gated',
        );

        // And the button that jumps to «my flat» in the list.
        $this->assertStringContainsString(
            '$ownsTheDebt ? $this->board->pageOfViewer($account) : null',
            (string)file_get_contents(__DIR__ . '/../../src/Telegram/Debt/Command/DebtBoardCommand.php'),
            '«📌 Моя квартира» makes the same claim in one fewer word',
        );
    }

    /** The monthly reminder is addressed to whoever the arrears belong to, and nobody else. */
    public function testTheMonthlyReminderSkipsTenants(): void
    {
        $this->assertStringContainsString(
            'if (!$user->owesForTheFlat()) {',
            (string)file_get_contents(__DIR__ . '/../../src/Command/DebtNotifyCommand.php'),
            'DebtNotifyCommand would otherwise bill the person renting the flat',
        );
    }
}
