<?php

namespace App\Service;

use App\Entity\Account;

/**
 * Is this upload a debtors file at all? Asked before the debt form writes anything.
 *
 * The debt upload treats its file as the full snapshot of who owes: every account with a
 * debt that the file does not name is reset to zero, unblocked and told «✅ Ваш борг
 * повністю погашено», and the residents' chat is told the house total. On 27.11.2026 at
 * 11:34 the apartment area registry was uploaded into this form by mistake — none of its
 * rows matched as a debt row, so 438 accounts were "paid off" and the chat read «Борг 0
 * грн». The right file followed 3 s later and re-blocked 229 people, each with a fresh
 * «🚫 ЗАБЛОКОВАНО».
 *
 * Two checks, both on the parsed file against the base as it stands, and either one
 * refuses the whole upload — nothing written, nothing sent, nothing announced:
 *  - not one row matches a known особовий рахунок: whatever this is, it is not the
 *    ОСББ's debtors list;
 *  - applying it would reset more than half of the accounts that owe today: debts are
 *    not paid off by the half-house between two monthly files.
 */
final class DebtFileGuard
{
    /** More than this share of today's debtors missing from the file → refused. */
    public const MAX_RESET_SHARE = 0.5;

    /**
     * @param array<string|int, float> $debtData особовий рахунок => борг, as parsed from the file
     * @param iterable<Account>        $accounts every account in the base
     * @return array{matched: int, debtors: int, would_reset: int, refusal: ?string}
     */
    public static function check(array $debtData, iterable $accounts): array
    {
        $inFile = array_fill_keys(array_map('strval', array_keys($debtData)), true);
        $matched = $debtors = $wouldReset = 0;

        foreach ($accounts as $account) {
            $number = (string)$account->getAccountNumber();
            $listed = isset($inFile[$number]);

            if ($listed) {
                $matched++;
            }

            if ($account->getDebt() !== null && (float)$account->getDebt() > 0) {
                $debtors++;

                if (!$listed) {
                    $wouldReset++;
                }
            }
        }

        $refusal = null;

        if ($matched === 0) {
            $refusal = sprintf(
                'Це не схоже на файл боржників: жоден з %d рядків файлу не збігся з особовими рахунками в базі. '
                . 'Файл не прийнято — нічого не змінено, мешканцям нічого не надіслано. '
                . 'Потрібен файл «Боржники»: колонка B — особовий рахунок, C — борг, дані з 3-го рядка. '
                . 'Реєстр площ сюди не завантажується.',
                count($debtData),
            );
        } elseif ($debtors > 0 && $wouldReset > $debtors * self::MAX_RESET_SHARE) {
            $refusal = sprintf(
                'Цей файл скинув би борг у %d з %d рахунків, які зараз мають борг, — це більше половини. '
                . 'Схоже, завантажено не той файл. Файл не прийнято — нічого не змінено, мешканцям нічого не надіслано. '
                . 'Якщо борги справді погашено масово, напишіть Івану.',
                $wouldReset,
                $debtors,
            );
        }

        return ['matched' => $matched, 'debtors' => $debtors, 'would_reset' => $wouldReset, 'refusal' => $refusal];
    }
}
