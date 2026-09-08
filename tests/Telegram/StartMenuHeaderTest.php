<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Telegram\Start\Command\StartCommand;
use PHPUnit\Framework\TestCase;

/**
 * The block above the main menu names what the resident owns.
 *
 * It is the only place in the bot where an особовий рахунок is readable, and that number
 * is what the accountant asks for on the phone. Two rules, both learned from a real
 * reading of the menu:
 *
 *  - **every object, not just the linked one.** `TelegramUser.account_id` points at one
 *    Account, but a household can be a flat plus a комірчина plus a паркомісце — three
 *    рахунки, tied together by `owner_group_id`. The other objects' numbers appeared
 *    nowhere in the bot at all.
 *  - **the kind of object comes from the особовий рахунок, never from a hardcoded word.**
 *    The header built its own "кв. %s" and so called a комірчина a flat — the same
 *    mistake the debtors' board was corrected for on 03.09.2026.
 */
class StartMenuHeaderTest extends TestCase
{
    private function account(string $number, string $unit, ?string $type = null, string $debt = '0'): Account
    {
        $account = (new Account())
            ->setAccountNumber($number)
            ->setApartmentNumber($unit)
            ->setHouseNumber('19')
            ->setStreet('Козацька')
            ->setDebt($debt);

        return $type === null ? $account : $account->setUnitType($type);
    }

    /**
     * Each object is billed separately, has its own threshold, and a debt on **any** of
     * them blocks booking for the whole household — so "which of my objects owes" has to
     * be answerable beside the рахунок itself, not only from the board below, which lists
     * an object only once it reaches the published list.
     */
    public function testEachObjectCarriesItsOwnDebt(): void
    {
        $header = StartCommand::renderHeader([
            $this->account('230085', '85', null, '3415.50'),
            $this->account('235168', '168', Account::UNIT_STORAGE, '0'),
        ], true);

        // Signed: a bare number beside a рахунок reads as a charge or a payment just as
        // easily as as arrears.
        $this->assertStringContainsString('−3 416 грн', $header);
        $this->assertStringContainsString('без боргу', $header);
    }

    /**
     * A zero is spelled out, never left blank: on a list of two lines a missing annotation
     * reads as missing data rather than as nothing owed.
     */
    public function testAnObjectThatOwesNothingSaysSoRatherThanSayingNothing(): void
    {
        $header = StartCommand::renderHeader([
            $this->account('230085', '85', null, '0'),
            $this->account('235168', '168', Account::UNIT_STORAGE, '0'),
        ], true);

        $this->assertSame(2, substr_count($header, 'без боргу'));
    }

    /**
     * Never a sum without a date. The «станом на» line lives in the debtors' block below,
     * and when that block falls silent as stale the header must not keep publishing
     * figures nobody can vouch for — the caller passes that same answer in.
     */
    public function testNoFigureIsPrintedWhenTheDebtDataIsTooOldToPublish(): void
    {
        $header = StartCommand::renderHeader([
            $this->account('230085', '85', null, '3415.50'),
            $this->account('235168', '168', Account::UNIT_STORAGE, '0'),
        ], false);

        $this->assertStringNotContainsString('грн', $header);
        $this->assertStringNotContainsString('без боргу', $header);
        $this->assertStringContainsString('<code>235168</code>', $header);
    }

    public function testASingleObjectKeepsTheWordingResidentsHaveBeenReading(): void
    {
        $header = StartCommand::renderHeader([$this->account('230085', '85')]);

        $this->assertStringContainsString('Козацька 19, кв. 85', $header);
        $this->assertStringContainsString('<code>230085</code>', $header);
        $this->assertStringContainsString('Ваш особовий рахунок', $header);
        $this->assertStringContainsString('не банківський', $header);
    }

    public function testEveryObjectOfTheHouseholdIsNamedWithItsOwnNumber(): void
    {
        $header = StartCommand::renderHeader([
            $this->account('230085', '85'),
            $this->account('235168', '168', Account::UNIT_STORAGE),
            $this->account('237138', '138', Account::UNIT_PARKING),
        ]);

        $this->assertStringContainsString('<code>230085</code>', $header);
        $this->assertStringContainsString('<code>235168</code>', $header);
        $this->assertStringContainsString('<code>237138</code>', $header);
    }

    /** A комірчина is not a flat, and neither is a паркомісце. */
    public function testNoObjectIsCalledAFlatUnlessItIsOne(): void
    {
        $header = StartCommand::renderHeader([
            $this->account('230085', '85'),
            $this->account('235168', '168', Account::UNIT_STORAGE),
            $this->account('237138', '138', Account::UNIT_PARKING),
        ]);

        $this->assertStringContainsString('кв. 85', $header);
        $this->assertStringContainsString('комірчина 168', $header);
        $this->assertStringContainsString('паркомісце 138', $header);
        $this->assertStringNotContainsString('кв. 168', $header);
        $this->assertStringNotContainsString('кв. 138', $header);
    }

    /** Also true when the комірчина is the only thing the person is linked to. */
    public function testALoneStorageUnitIsStillNotCalledAFlat(): void
    {
        $header = StartCommand::renderHeader([
            $this->account('235168', '168', Account::UNIT_STORAGE),
        ]);

        $this->assertStringContainsString('Козацька 19, комірчина 168', $header);
        $this->assertStringNotContainsString('кв.', $header);
    }

    /**
     * An object with no особовий рахунок has nothing to say here, and the whole block
     * disappearing over one such row would take the resident's real number with it.
     */
    public function testAnObjectWithoutANumberIsSkippedRatherThanBreakingTheBlock(): void
    {
        $header = StartCommand::renderHeader([
            $this->account('230085', '85'),
            $this->account('', '168', Account::UNIT_STORAGE),
        ]);

        $this->assertStringContainsString('<code>230085</code>', $header);
        $this->assertStringContainsString('Ваш особовий рахунок', $header);
    }

    /** Nothing to name, nothing rendered — StartCommand concatenates this blindly. */
    /**
     * Who else is on this рахунок.
     *
     * The bot is the only place a resident can check that the accountant's linking actually
     * happened. Vitalii asked on 08.09.2026 to have his wife added; she was, and nothing on
     * his screen changed — so the next thing he does is ask again. It also lets somebody
     * notice a name that should not be on their flat, which is otherwise visible only to an
     * admin.
     */
    public function testItNamesTheRestOfTheHousehold(): void
    {
        $header = StartCommand::renderHeader(
            [$this->account('320092', '92')],
            false,
            [$this->person('Конакбаєва Марина Василівна', TelegramUser::ROLE_FAMILY)],
        );

        $this->assertStringContainsString('Конакбаєва Марина Василівна', $header);
        $this->assertStringContainsString('член сім', $header, 'the role is worth seeing and correcting');
    }

    /**
     * The registry name beats the Telegram one: `full_name` is what the accountant typed
     * off a квитанція, the other is whatever the person called themselves, and «Vitalii» is
     * a worse answer to «хто ще на моєму рахунку».
     */
    public function testItPrefersTheRegistryNameAndFallsBackToTelegram(): void
    {
        $both = $this->person('Конакбаєв Віталій Петрович');
        $both->setFirstName('Vitalii');

        $this->assertStringContainsString(
            'Конакбаєв Віталій Петрович',
            StartCommand::renderHeader([$this->account('320092', '92')], false, [$both]),
        );

        $telegramOnly = new TelegramUser();
        $telegramOnly->setFirstName('Vitalii');

        $this->assertStringContainsString(
            'Vitalii',
            StartCommand::renderHeader([$this->account('320092', '92')], false, [$telegramOnly]),
        );
    }

    /** Silent for a household of one — «на рахунку більше нікого» is noise. */
    public function testItSaysNothingWhenNobodyElseIsOnTheAccount(): void
    {
        $header = StartCommand::renderHeader([$this->account('320092', '92')]);

        $this->assertStringNotContainsString('На цьому рахунку', $header);
    }

    /** And on the multi-object header too, where the household line joins the list. */
    public function testTheHouseholdShowsOnAMultiObjectHeaderAsWell(): void
    {
        $header = StartCommand::renderHeader(
            [$this->account('230085', '85'), $this->account('235168', '168')],
            false,
            [$this->person('Конакбаєва Марина Василівна')],
        );

        $this->assertStringContainsString('Конакбаєва Марина Василівна', $header);
    }

    private function person(string $fullName, ?string $role = null): TelegramUser
    {
        $user = new TelegramUser();
        $user->setFullName($fullName);

        if ($role !== null) {
            $user->setRole($role);
        }

        return $user;
    }

    public function testNothingIsRenderedWhenThereIsNoAccountAtAll(): void
    {
        $this->assertSame('', StartCommand::renderHeader([]));
        $this->assertSame('', StartCommand::renderHeader([$this->account('', '85')]));
    }
}
