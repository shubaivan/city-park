<?php

namespace App\Service;

use App\Service\SchedulePavilionService;
use App\Service\OsbbContacts;
use App\Entity\Account;
use App\Repository\PhotoUploadRequestRepository;
use App\Repository\TariffRepository;
use Doctrine\ORM\EntityManagerInterface;

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
        private TariffRepository $tariffRepository,
        private EntityManagerInterface $em,
    ) {
    }

    /** Rendered from OsbbContacts so a changed number changes everywhere at once. */
    /**
     * «(площа 50.6 м² × 13.50 грн/м² × 1.5 — півтори місячні нарахування)».
     *
     * Empty when the threshold did not come from that formula: `getThresholdFor()` falls
     * back to the flat DEBT_BLOCK_THRESHOLD when the area or the tariff is missing, and
     * explaining a sum with numbers that were not used in it is worse than not explaining
     * it at all.
     */
    private function thresholdExplained(Account $account): string
    {
        $area = (float)($account->getArea() ?? 0);
        $price = (float)$this->tariffRepository->getOrCreate($this->em)->getPricePerMeter();

        if ($area <= 0 || $price <= 0) {
            return '';
        }

        return sprintf(
            "<i>Це %s м² × %s грн/м² × %s — півтори місячні нарахування ОСББ.</i>\n",
            rtrim(rtrim(number_format($area, 2, '.', ' '), '0'), '.'),
            number_format($price, 2, '.', ' '),
            DebtPolicy::OVER_FACTOR,
        );
    }

    private static function accountantContact(): string
    {
        return OsbbContacts::askThem("Зв'яжіться з ОСББ:");
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
            $pavilionName = SchedulePavilionService::pavilionName($blockedReq->getPavilion());
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

        // Which object, by name.
        //
        // One person can now hold several — a flat, a parking space, a комірчина — and this
        // branch fires on the account they are linked to while the neighbouring branch (a
        // debt on another object of the same owner) names the object it is talking about.
        // Two messages about the same subject, one of which says «поточний борг 3 415.50»
        // and leaves the reader to guess which door it belongs to.
        $header = "🚫 <b>Бронювання недоступне — ваш аккаунт призупинено.</b>\n"
            . sprintf(
                "<i>%s · рахунок %s</i>\n\n",
                htmlspecialchars($account->getPlaceLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string)$account->getAccountNumber(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );

        if ($this->debtPolicy->isAccountBlocked($account)) {
            $debt = (float)($account->getDebt() ?? 0);
            $threshold = $this->debtPolicy->getThresholdFor($account);

            return $header
                . "💰 <b>Причина:</b> заборгованість понад допустимий поріг.\n"
                . sprintf("• Поточний борг: <b>%s грн</b>\n", number_format($debt, 2, '.', ' '))
                . sprintf("• Поріг блокування: <b>%s грн</b>\n", number_format($threshold, 2, '.', ' '))
                // Where that number comes from. Without it «1 024.65» reads as a figure
                // somebody chose for this flat, and the first thing a person does with a
                // number they cannot check is ring the accountant to dispute it. It is
                // arithmetic on two things they already know — their own area and the
                // tariff — so showing the sum turns an accusation into a receipt.
                . $this->thresholdExplained($account)
                . "\nБудь ласка, сплатіть заборгованість, щоб відновити можливість бронювання.\n\n"
                . self::accountantContact();
        }

        $blockedReq = $this->photoRequestRepository->findEarliestBlockedOpen($account);
        if ($blockedReq !== null) {
            $pavilionName = SchedulePavilionService::pavilionName($blockedReq->getPavilion());
            $sessionLabel = $blockedReq->getSessionStartAt()->format('d.m.Y H:i');

            $msg = $header
                . "📸 <b>Причина:</b> не завантажене фото після бронювання.\n"
                . sprintf("• Сесія: <b>%s</b>, альтанка «<b>%s</b>»\n\n", $sessionLabel, $pavilionName);

            if ($this->photoService->isUploadStillAllowed($blockedReq, $now)) {
                $msg .= "Натисніть «📸 Завантажити фото» та надішліть фото — "
                    . "аккаунт розблокується автоматично.\n\n"
                    . "Якщо виникли труднощі — " . self::accountantContact();
            } else {
                $msg .= "Час для самостійного завантаження фото вже минув.\n\n"
                    . self::accountantContact();
            }

            return $msg;
        }

        return $header
            . "Для відновлення доступу до бронювання " . self::accountantContact();
    }
}
