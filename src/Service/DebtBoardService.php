<?php

namespace App\Service;

use App\Entity\Account;
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
 * 3. **Silence beats a stale accusation.** Past STALE_AFTER_DAYS the board disappears
 *    entirely rather than naming somebody over numbers nobody can vouch for. This is
 *    the whole reason Account::$debt_updated_at exists.
 */
class DebtBoardService
{
    /**
     * After this many days without an import the board hides itself.
     *
     * Charges are monthly, so a file older than a month means at least one full cycle
     * of payments went unrecorded — by then the board would be naming people who have
     * since paid, which is exactly the accusation it must not make.
     */
    public const STALE_AFTER_DAYS = 30;

    public const TOP_SIZE = 3;

    /** Telegram's hard limit is 4096; leave room for the footer we append last. */
    private const MAX_REPORT_CHARS = 3500;

    private const MEDALS = ['🥇', '🥈', '🥉'];

    public function __construct(
        private AccountRepository $accountRepository,
    ) {}

    public function lastImportAt(): ?\DateTimeImmutable
    {
        return $this->accountRepository->lastDebtImportAt();
    }

    public function isStale(): bool
    {
        $at = $this->lastImportAt();

        if (!$at instanceof \DateTimeImmutable) {
            return true;
        }

        return $at < new \DateTimeImmutable(sprintf('-%d days', self::STALE_AFTER_DAYS));
    }

    /**
     * Is there anything to show at all? False when the data is stale or nobody owes.
     */
    public function isAvailable(): bool
    {
        if ($this->isStale()) {
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
                self::MEDALS[$i] ?? '▫️',
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
     * The full list behind the 💸 button, largest debt first.
     */
    public function report(?Account $viewer): string
    {
        if (!$viewer instanceof Account) {
            return "💸 <b>Звіт боржників</b>\n\n"
                . 'Цей розділ доступний лише мешканцям, чий Telegram прив’язано до особового '
                . "рахунку ОСББ.\n\nНатисніть /phone і поділіться номером телефону.";
        }

        if ($this->isStale()) {
            return "💸 <b>Звіт боржників</b>\n\n"
                . "Дані про борги ще не оновлювались цього місяця, тому список тимчасово приховано — "
                . "щоб не показувати як боржника того, хто вже сплатив.\n\n"
                . '<i>Актуальну суму по вашій квартирі підкаже бухгалтер ОСББ.</i>';
        }

        $debtors = $this->accountRepository->findDebtors();
        $totals = $this->accountRepository->debtTotals();

        if ($debtors === []) {
            return "🎉 <b>Боржників немає!</b>\n\n"
                . "Жодної квартири з боргом. Таке буває раз на все життя — святкуємо! 🥳\n\n"
                . sprintf('📅 <i>Дані станом на %s</i>', $this->asOfLabel());
        }

        $head = "💸 <b>ЗВІТ БОРЖНИКІВ</b> 💸\n"
            . "<i>Від найбільшого до найменшого. Хто сплатив — той зникає зі списку.</i>\n\n";

        $body = '';
        $shown = 0;

        foreach ($debtors as $i => $account) {
            $line = sprintf(
                "%s %s — <b>%s грн</b>%s\n",
                self::MEDALS[$i] ?? sprintf('<b>%d.</b>', $i + 1),
                $this->place($account),
                $this->money((float)$account->getDebt()),
                $this->isViewer($account, $viewer) ? ' 📌 <i>(це ви)</i>' : '',
            );

            if (mb_strlen($body . $line) > self::MAX_REPORT_CHARS) {
                break;
            }

            $body .= $line;
            $shown++;
        }

        $foot = "\n" . sprintf(
            "💰 <b>Разом: %s грн</b> · %d квартир\n",
            $this->money($totals['total']),
            $totals['debtors'],
        );

        if ($shown < count($debtors)) {
            $foot .= sprintf("<i>Показано перших %d із %d.</i>\n", $shown, count($debtors));
        }

        $foot .= sprintf("📅 <i>Дані станом на %s. Суми округлені до гривні.</i>", $this->asOfLabel());

        return $head . $body . $foot;
    }

    /**
     * A debtor is told where they stand before anyone has to tell them in person; a
     * resident who owes nothing gets the one line that makes the board bearable to
     * read — that they are not on it.
     */
    private function viewerLine(Account $viewer): string
    {
        $debt = (float)$viewer->getDebt();

        if ($debt <= 0) {
            return "✅ <i>Ваша квартира боргів не має. Дякуємо!</i>";
        }

        $position = 1;
        foreach ($this->accountRepository->findDebtors() as $i => $account) {
            if ($this->isViewer($account, $viewer)) {
                $position = $i + 1;
                break;
            }
        }

        return sprintf(
            '📌 <i>Ваша квартира у списку: %s грн, %d місце.</i>',
            $this->money($debt),
            $position,
        );
    }

    private function isViewer(Account $account, Account $viewer): bool
    {
        return $account->getId() !== null && $account->getId() === $viewer->getId();
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

        // Parking spaces and storage rooms carry their own wording in apartment_number
        // ("Паркінг 138"), so only a bare number gets the "кв." prefix.
        $unit = match (true) {
            $apartment === '' => 'без номера',
            preg_match('/^\d+[a-zA-Zа-яА-ЯіїєґІЇЄҐ]?$/u', $apartment) === 1 => 'кв. ' . $this->esc($apartment),
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

    private function money(float $value): string
    {
        return number_format($value, 0, '.', ' ');
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
