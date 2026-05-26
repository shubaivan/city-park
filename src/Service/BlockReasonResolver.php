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
    ) {
    }

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
}
