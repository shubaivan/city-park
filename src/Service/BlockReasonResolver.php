<?php

namespace App\Service;

use App\Entity\Account;
use App\Repository\PhotoUploadRequestRepository;

/**
 * Answers "why is this Account currently is_active=false?" by inspecting the same
 * state the blocking processes themselves use (DebtPolicy, photo_upload_request).
 * Derived from current data — no separate column to keep in sync.
 */
final class BlockReasonResolver
{
    public function __construct(
        private DebtPolicy $debtPolicy,
        private PhotoUploadRequestRepository $photoRequestRepository,
        private PavilionPhotoService $photoService,
    ) {
    }

    private const ACCOUNTANT_CONTACT = "Зв'яжіться з Аліною Бухгалтером — +380 93 658 32 02";

    /**
     * @return array{code:string, label:string, details:?string}|null null when the account is active.
     */
    public function resolve(?Account $account): ?array
    {
        if ($account === null) {
            return null;
        }
        if ($account->isActive() === true) {
            return null;
        }

        if ($this->debtPolicy->isAccountBlocked($account)) {
            $debt = (float)($account->getDebt() ?? 0);
            $threshold = $this->debtPolicy->getThresholdFor($account);
            return [
                'code' => 'debt',
                'label' => '💰 Борг понад поріг',
                'details' => sprintf('%.2f грн (поріг %.2f грн)', $debt, $threshold),
            ];
        }

        $blockedReq = $this->photoRequestRepository->findEarliestBlockedOpen($account);
        if ($blockedReq !== null) {
            $pavilionName = $blockedReq->getPavilion() === 1 ? 'Перша' : 'Друга';
            return [
                'code' => 'photo',
                'label' => '📸 Не завантажене фото',
                'details' => sprintf(
                    'сесія %s, альтанка «%s» (req #%d)',
                    $blockedReq->getSessionStartAt()->format('d.m.Y H:i'),
                    $pavilionName,
                    $blockedReq->getId(),
                ),
            ];
        }

        return [
            'code' => 'manual',
            'label' => '✋ Вручну адміном',
            'details' => 'Автоматичних причин не виявлено',
        ];
    }

    /**
     * User-facing Telegram message (HTML) explaining why booking is blocked, with
     * the concrete numbers/details the user needs to act. Returns null when the
     * account is active. Shares the same reason priority as resolve().
     */
    public function botMessage(?Account $account, \DateTime $now): ?string
    {
        if ($account === null || $account->isActive() === true) {
            return null;
        }

        $header = "🚫 <b>Бронювання недоступне — ваш аккаунт призупинено.</b>\n\n";

        if ($this->debtPolicy->isAccountBlocked($account)) {
            $debt = (float)($account->getDebt() ?? 0);
            $threshold = $this->debtPolicy->getThresholdFor($account);

            return $header
                . "💰 <b>Причина:</b> заборгованість понад допустимий поріг.\n"
                . sprintf("• Поточний борг: <b>%s грн</b>\n", number_format($debt, 2, '.', ' '))
                . sprintf("• Поріг блокування: <b>%s грн</b>\n\n", number_format($threshold, 2, '.', ' '))
                . "Будь ласка, сплатіть заборгованість, щоб відновити можливість бронювання.\n\n"
                . self::ACCOUNTANT_CONTACT;
        }

        $blockedReq = $this->photoRequestRepository->findEarliestBlockedOpen($account);
        if ($blockedReq !== null) {
            $pavilionName = $blockedReq->getPavilion() === 1 ? 'Перша' : 'Друга';
            $sessionLabel = $blockedReq->getSessionStartAt()->format('d.m.Y H:i');

            $msg = $header
                . "📸 <b>Причина:</b> не завантажене фото після бронювання.\n"
                . sprintf("• Сесія: <b>%s</b>, альтанка «<b>%s</b>»\n\n", $sessionLabel, $pavilionName);

            if ($this->photoService->isUploadStillAllowed($blockedReq, $now)) {
                $msg .= "Натисніть «📸 Завантажити фото» та надішліть фото — "
                    . "аккаунт розблокується автоматично.\n\n"
                    . "Якщо виникли труднощі — " . self::ACCOUNTANT_CONTACT;
            } else {
                $msg .= "Час для самостійного завантаження фото вже минув.\n\n"
                    . self::ACCOUNTANT_CONTACT;
            }

            return $msg;
        }

        return $header
            . "Для відновлення доступу до бронювання " . self::ACCOUNTANT_CONTACT;
    }
}
