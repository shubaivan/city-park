<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\DebtSnapshot;
use App\Repository\AccountRepository;

/**
 * The debtors' board — «дошка пошани» — and the full debtors' report.
 *
 * A deliberate piece of social pressure, asked for by the head of the ОСББ: the three
 * largest debts and the house's total are shown to every verified resident on the main
 * menu, and a button opens the complete list. Apartment numbers only: no names, no
 * phone numbers, nothing that is not already written on a door in the під'їзд.
 *
 * Three rules make it defensible, and each of them is load-bearing:
 *
 * 1. **Verified residents only.** The caller passes the viewer's Account; an unlinked
 *    visitor — someone who opened the bot through 🔑 Оренда to look at flats — gets
 *    nothing. Who owes the ОСББ money is the house's business, not a flat-hunter's.
 * 2. **Never published without a date.** The numbers move only when the accountant
 *    uploads a file, so every render carries "станом на …".
 * 3. **Tied to the accountant's action, not to a clock.** There is no age limit — the
 *    board shows the ОСББ's last official statement and stamps it, and a file that was
 *    never uploaded at all publishes nothing. This is why Account::$debt_updated_at
 *    exists: not to expire the data, but to date it.
 */
class DebtBoardService
{
    /**
     * Three was the first shape; the head of the ОСББ asked for five — a podium of three
     * lets the fourth-largest debtor feel comfortably out of shot, and on prod the drop
     * from 3rd to 5th place is 5 402 → 2 500 грн, still real money.
     */
    public const TOP_SIZE = 5;

    private const MEDALS = ['🥇', '🥈', '🥉'];

    /** Places 4 and 5 keep the podium's joke register rather than dropping to a bullet. */
    private const PODIUM = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'];

    /**
     * How many are named in the residents'-chat announcement.
     *
     * Ten at first; twenty since 04.09.2026. The chat post is the only *push* half of this
     * feature — it reaches all 77 members whether or not they ever open the bot — and with
     * 149 flats owing money a top ten is a list of the extremes, not a picture of the
     * house. Twenty is still one screen and still well inside Telegram's 4096 characters.
     */
    public const ANNOUNCE_SIZE = 20;

    /**
     * How many lines one page of the in-bot report carries.
     *
     * The report used to fill a message up to a character budget and then stop with
     * "показано перших N із M" — which named the top of the list and silently hid the rest,
     * so a resident could not check their own neighbours and the ОСББ could not point at
     * anything below ~40th place. Paging shows all 149 without ever building a message
     * Telegram would refuse.
     */
    public const PAGE_SIZE = 15;

    private const RANKS = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];

    public function __construct(
        private AccountRepository $accountRepository,
    ) {}

    public function lastImportAt(): ?\DateTimeImmutable
    {
        return $this->accountRepository->lastDebtImportAt();
    }

    /**
     * Is there anything to show at all?
     *
     * There is deliberately **no age limit**. An earlier version hid the board once the
     * figures passed 30 days, which was a guess at the accountant's cadence rather than
     * a fact: she uploads roughly monthly, so the guess blanked the board every cycle
     * just before the next file, and one late upload would have removed it entirely.
     * The board is tied to her action instead — it shows the ОСББ's last official
     * statement, always stamped «станом на …», and the reader judges the age themselves.
     *
     * The one thing that is not a guess: if no file was ever imported there is no date
     * to stamp and nothing to stand behind, so nothing is published.
     */
    public function isAvailable(): bool
    {
        if (!$this->lastImportAt() instanceof \DateTimeImmutable) {
            return false;
        }

        return $this->accountRepository->debtTotals()['debtors'] > 0;
    }

    /**
     * The block that sits above the main menu. Empty string when it must not render —
     * StartCommand concatenates it blindly, so "nothing to say" has to be harmless.
     */
    public function menuBlock(?Account $viewer): string
    {
        if (!$viewer instanceof Account || !$this->isAvailable()) {
            return '';
        }

        $totals = $this->accountRepository->debtTotals();
        $top = $this->accountRepository->findDebtors(self::TOP_SIZE);

        $lines = [
            sprintf(
                '💸 <b>Борг мешканців перед ОСББ: %s грн</b>',
                $this->money($totals['total']),
            ),
            sprintf('<i>Це борг %d квартир — гроші, яких немає на ремонт і благоустрій.</i>', $totals['debtors']),
            '',
            '🏆 <b>ДОШКА «ПОШАНИ»</b> 🏆',
            '<i>Призери місяця — оплески! 👏</i>',
            '',
        ];

        foreach ($top as $i => $account) {
            $lines[] = sprintf(
                '%s %s — <b>%s грн</b>%s',
                self::PODIUM[$i] ?? '▫️',
                $this->place($account),
                $this->money((float)$account->getDebt()),
                $i === 0 ? ' 👑' : '',
            );
        }

        $lines[] = '';
        $lines[] = $this->viewerLine($viewer);
        $lines[] = sprintf('📅 <i>Дані станом на %s</i>', $this->asOfLabel());
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    /**
     * The full list behind the 💸 button, largest debt first, one page at a time.
     *
     * Paged rather than truncated. The first shape filled a message up to a character
     * budget and stopped with «показано перших N із M»: it named the top of the
     * list and hid everyone below, which on 149 debtors meant roughly the first forty.
     * That is the wrong forty to publish — the extremes are already on the podium in the
     * menu, and a resident checking whether *their* neighbour pays could never get there.
     */
    public function report(?Account $viewer, int $page = 1): string
    {
        if (!$viewer instanceof Account) {
            return "💸 <b>Звіт боржників</b>\n\n"
                . 'Цей розділ доступний лише мешканцям, чий Telegram прив’язано до особового '
                . "рахунку ОСББ.\n\nНатисніть /phone і поділіться номером телефону.";
        }

        if (!$this->lastImportAt() instanceof \DateTimeImmutable) {
            return "💸 <b>Звіт боржників</b>\n\n"
                . "Дані про борги ще не завантажені — список з'явиться після найближчого "
                . "оновлення від бухгалтерії ОСББ.";
        }

        $debtors = $this->accountRepository->findDebtors();
        $totals = $this->accountRepository->debtTotals();

        if ($debtors === []) {
            return "🎉 <b>Боржників немає!</b>\n\n"
                . "Жодної квартири з боргом. Таке буває раз на все життя — святкуємо! 🥳\n\n"
                . sprintf('📅 <i>Дані станом на %s</i>', $this->asOfLabel());
        }

        $pages = $this->pageCount();
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $head = "💸 <b>ЗВІТ БОРЖНИКІВ</b> 💸\n"
            . "<i>Від найбільшого до найменшого. Хто сплатив — той зникає зі списку.</i>\n\n";

        $body = '';

        foreach (array_slice($debtors, $offset, self::PAGE_SIZE, true) as $i => $account) {
            $body .= sprintf(
                "%s %s — <b>%s грн</b>%s\n",
                self::MEDALS[$i] ?? sprintf('<b>%d.</b>', $i + 1),
                $this->place($account),
                $this->money((float)$account->getDebt()),
                $this->isViewer($account, $viewer) ? ' 📌 <i>(це ви)</i>' : '',
            );
        }

        $foot = "\n" . sprintf(
            "💰 <b>Разом: %s грн</b> · %d квартир\n",
            $this->money($totals['total']),
            $totals['debtors'],
        );

        if ($pages > 1) {
            $foot .= sprintf(
                "<i>Сторінка %d з %d · місця %d–%d.</i>\n",
                $page,
                $pages,
                $offset + 1,
                min($offset + self::PAGE_SIZE, count($debtors)),
            );
        }

        // Their own line, on every page. Without it the viewer has to leaf through ten
        // pages to find out whether they are on the list at all — and "am I on it?" is the
        // first question anyone opens this with.
        $foot .= $this->viewerLine($viewer) . "\n";

        $foot .= sprintf("📅 <i>Дані станом на %s. Суми округлені до гривні.</i>", $this->asOfLabel());

        return $head . $body . $foot;
    }

    /** How many pages the in-bot report has. At least one, even with nobody on it. */
    public function pageCount(): int
    {
        $debtors = $this->accountRepository->debtTotals()['debtors'] ?? 0;

        return max(1, (int)ceil($debtors / self::PAGE_SIZE));
    }

    /**
     * The page the viewer's first owing object is on, or null when nothing the household
     * owns is on the list. Group-aware, so an owner whose комірчина owes but whose flat does not
     * still lands on the right page instead of getting no button at all.
     *
     * Feeds the «📌 Моя квартира» button: on 149 debtors, "you are 63rd" is useless if
     * getting there means tapping ➡️ four times.
     */
    public function pageOfViewer(?Account $viewer): ?int
    {
        if (!$viewer instanceof Account) {
            return null;
        }

        foreach ($this->accountRepository->findDebtors() as $i => $account) {
            if ($this->isViewer($account, $viewer)) {
                return (int)floor($i / self::PAGE_SIZE) + 1;
            }
        }

        return null;
    }

    /**
     * The post for the residents' chat, published after each debt import.
     *
     * Unlike the menu board this is *push*: it lands in every member's notifications and
     * is forwardable in one tap, so it leads with the figures the whole house needs — the
     * total, the number of flats, and whether that moved since last month — and only then
     * names the ten largest. The date is in the header for the same reason it is on the
     * board: a list of debtors without one is an accusation nobody can check.
     *
     * $previous is the snapshot before this import, or null on the very first one.
     */
    public function chatAnnouncement(DebtSnapshot $current, ?DebtSnapshot $previous): string
    {
        $lines = [
            '📊 <b>Стан розрахунків з ОСББ</b>',
            sprintf('<i>станом на %s</i>', $this->asOfLabel()),
            '',
            sprintf('💸 Борг мешканців: <b>%s грн</b>', $this->money($current->getTotal())),
            sprintf('🏠 Квартир з боргом: <b>%d</b>', $current->getDebtors()),
        ];

        if ($previous instanceof DebtSnapshot) {
            $lines[] = $this->trendLine($current, $previous);
        }

        $top = $this->accountRepository->findDebtors(self::ANNOUNCE_SIZE);

        if ($top !== []) {
            $lines[] = '';
            // The heading counts what is actually printed: on a small house the list can
            // be shorter than ANNOUNCE_SIZE, and «Двадцятка» over twelve names is the kind
            // of small lie that makes people distrust the figures above it.
            $lines[] = sprintf('🏆 <b>Найбільші борги — %d «лідерів»</b>, вітаємо! 👏', count($top));
            $lines[] = '';

            foreach ($top as $i => $account) {
                $lines[] = sprintf(
                    '%s %s — <b>%s грн</b>%s',
                    self::RANKS[$i] ?? sprintf('%d.', $i + 1),
                    $this->place($account),
                    $this->money((float)$account->getDebt()),
                    $i === 0 ? ' 👑' : '',
                );
            }
        }

        $lines[] = '';
        $lines[] = '<i>Свій борг і повний список — у боті, кнопка 💸 Звіт боржників.</i>';

        return implode("\n", $lines);
    }

    private function trendLine(DebtSnapshot $current, DebtSnapshot $previous): string
    {
        $delta = $current->getTotal() - $previous->getTotal();
        $since = $previous->getTakenAt()->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('d.m.Y');

        if (abs($delta) < 1) {
            return sprintf('➖ З %s борг не змінився.', $since);
        }

        return $delta < 0
            ? sprintf('📉 З %s борг <b>зменшився на %s грн</b>. Дякуємо тим, хто сплатив!', $since, $this->money(abs($delta)))
            : sprintf('📈 З %s борг <b>зріс на %s грн</b>.', $since, $this->money($delta));
    }

    /**
     * A debtor is told where they stand before anyone has to tell them in person; a
     * resident who owes nothing gets the one line that makes the board bearable to
     * read — that they are not on it.
     */
    private function viewerLine(Account $viewer): string
    {
        // The whole household, not just the object this person happens to be linked to.
        // A flat, a paркомісце and a комірчина are three особові рахунки with three debts
        // and three thresholds; reading only the linked one told an owner "боргів немає"
        // while another of their own objects sat on the list below.
        $objects = $this->accountRepository->findGroupSiblings($viewer);

        // One pass over the list for the whole group: it is the same query the board
        // above already ran, and a per-object lookup would repeat it once per object.
        $positions = [];
        foreach ($this->accountRepository->findDebtors() as $i => $account) {
            if ($account->getId() !== null) {
                $positions[$account->getId()] = $i + 1;
            }
        }

        $owing = array_values(array_filter(
            $objects,
            static fn (Account $a): bool => $a->getId() !== null && isset($positions[$a->getId()]),
        ));

        // The singular wording is kept exactly as it was: all but one object on prod is
        // somebody's only one, and this is the line the whole house reads on every menu.
        if (count($objects) === 1) {
            return $owing === []
                ? '✅ <i>Ваша квартира боргів не має. Дякуємо!</i>'
                : sprintf(
                    '📌 <i>Ваша квартира у списку: %s грн, %d місце.</i>',
                    $this->money((float)$viewer->getDebt()),
                    $positions[$viewer->getId()],
                );
        }

        if ($owing === []) {
            return '✅ <i>Ваші об’єкти боргів не мають. Дякуємо!</i>';
        }

        return implode("\n", array_map(
            fn (Account $a): string => sprintf(
                '📌 <i>%s у списку: %s грн, %d місце.</i>',
                $this->place($a),
                $this->money((float)$a->getDebt()),
                $positions[$a->getId()],
            ),
            $owing,
        ));
    }

    /**
     * Whether this row is one of the viewer's own objects — theirs, or another object of
     * the same household.
     *
     * Compared against `owner_group_id` rather than by loading the group, because this is
     * called once per row of a 149-line list. Only an *explicit* group counts: an account
     * with no group must never match by its bare id, or the day somebody is unlinked their
     * id could still equal another household's group number.
     */
    private function isViewer(Account $account, Account $viewer): bool
    {
        if ($account->getId() === null) {
            return false;
        }

        if ($account->getId() === $viewer->getId()) {
            return true;
        }

        return $account->getOwnerGroupId() !== null
            && $account->getOwnerGroupId() === $viewer->getEffectiveGroupId();
    }

    /**
     * How an account is named on the board.
     *
     * **The building number is not decoration.** The ЖК is five buildings on one street
     * (Козацька 17, 19, 21, 23, 27) and apartment numbers repeat across them: on the day
     * this was written, "кв. 76" was two different families owing 5 402 and 651 грн.
     * A board that prints the apartment alone accuses both of the larger debt, which is
     * how a nudge turns into a defamation row. Never drop the building.
     */
    private function place(Account $account): string
    {
        $apartment = trim((string)$account->getApartmentNumber());
        $house = trim((string)$account->getHouseNumber());

        // What kind of unit it is comes from the особовий рахунок, not from the text.
        //
        // Only two of the eight non-flat accounts on prod spell it out in
        // `apartment_number` ("Паркінг 138"); the other six carry a bare number, and the
        // old rule — bare number ⇒ "кв." — published a parking space owing 1 330 грн as
        // «буд. 19, кв. 191». No flat with that number exists in that building *today*,
        // which is the only reason it has not yet accused anybody: the moment the
        // accountant adds one, this is the «кв. 76 is two households» case the building
        // rule exists to prevent, with the ЖК's own board doing the accusing.
        $prefix = match (true) {
            $account->isStorage() => 'комірчина ',
            $account->isParking() => 'паркомісце ',
            default => 'кв. ',
        };

        $unit = match (true) {
            $apartment === '' => 'без номера',
            preg_match('/^\d+[a-zA-Zа-яА-ЯіїєґІЇЄҐ]?$/u', $apartment) === 1 => $prefix . $this->esc($apartment),
            default => $this->esc($apartment),
        };

        return $house === '' ? $unit : sprintf('буд. %s, %s', $this->esc($house), $unit);
    }

    private function asOfLabel(): string
    {
        $at = $this->lastImportAt();

        return $at instanceof \DateTimeImmutable
            ? $at->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('d.m.Y')
            : 'невідомо';
    }

    /**
     * Rounded to the hryvnia — but never to nothing.
     *
     * The board is a list of debtors, so a line reading «0 грн» is a contradiction on its
     * face. Amounts under a hryvnia no longer reach the list at all
     * (AccountRepository::MIN_PUBLISHED_DEBT); this is the second guard, for a total or a
     * viewer's own line that rounds down.
     */
    private function money(float $value): string
    {
        if ($value > 0 && $value < 1) {
            return 'менше 1';
        }

        return number_format($value, 0, '.', ' ');
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
