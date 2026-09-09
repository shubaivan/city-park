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
     * «Ваша квартира — 50.6 м², тариф ОСББ — 13.50 грн/м². Поріг = 50.6 × 13.50 × 1.5.»
     *
     * Each input named as something the reader already knows about themselves before the
     * multiplication, rather than three bare numbers: the point is that they can check it
     * against their own квитанція without ringing anybody, and «50.6» means nothing until
     * it is called their own area.
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

        $areaLabel = rtrim(rtrim(number_format($area, 2, '.', ' '), '0'), '.');

        // Each number named as something the reader already knows about themselves, before
        // the multiplication that turns them into the threshold. «50.6 × 13.50 × 1.5» is
        // arithmetic; «ваша квартира — 50.6 м²» is the same arithmetic they can check
        // against their own квитанція without asking anybody.
        return sprintf(
            "<i>Площа — %s м², тариф ОСББ — %s грн/м².\n"
            . "Поріг = %s × %s × %s, тобто півтори місячні нарахування.</i>\n",
            $areaLabel,
            number_format($price, 2, '.', ' '),
            $areaLabel,
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

        // Every object of the household that is over its own threshold, not just the one
        // this person happens to be linked to. A debt on the комірчина blocks booking for
        // the whole owner group, and naming only the flat left an admin reading «борг
        // понад поріг» beside a flat that owes nothing.
        $blocking = $this->debtPolicy->getBlockingSiblings($account);

        if ($blocking !== []) {
            $details = [];

            foreach ($blocking as $unit) {
                $details[] = sprintf(
                    '%s: %.2f грн (поріг %.2f грн)',
                    $unit->getUnitLabel(),
                    (float)($unit->getDebt() ?? 0),
                    $this->debtPolicy->getThresholdFor($unit),
                );
            }

            return [
                'code' => 'debt',
                'label' => '💰 Борг понад поріг',
                'details' => implode('; ', $details),
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
        $header = "🚫 <b>Бронювання недоступне — ваш акаунт призупинено.</b>\n"
            . sprintf(
                "<i>%s · рахунок %s</i>\n\n",
                htmlspecialchars($account->getPlaceLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string)$account->getAccountNumber(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );

        $blocking = $this->debtPolicy->getBlockingSiblings($account);

        if ($blocking !== []) {
            // Every object of the household that owes over its own threshold, each with
            // its own arithmetic. A debt on the комірчина blocks booking for the whole
            // owner group, and the previous message named only the object this person was
            // linked to — so somebody whose flat was clean read «заборгованість понад
            // поріг» beside a flat with no debt on it and had no way to work out why.
            //
            // The debts are **not** summed and must not be: each object's threshold comes
            // from its own area, so adding two debts to compare against one threshold
            // compares a total against half a rule.
            $body = $header . "💰 <b>Причина:</b> заборгованість понад допустимий поріг.\n";

            if (count($blocking) > 1) {
                $body .= "<i>Блокування діє на всі об’єкти господарства, навіть якщо винен один.</i>\n";
            }

            foreach ($blocking as $unit) {
                $body .= sprintf(
                    "\n<b>%s</b>\n• Поточний борг: <b>%s грн</b>\n• Поріг блокування: <b>%s грн</b>\n%s",
                    htmlspecialchars($unit->getPlaceLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    number_format((float)($unit->getDebt() ?? 0), 2, '.', ' '),
                    number_format($this->debtPolicy->getThresholdFor($unit), 2, '.', ' '),
                    $this->thresholdExplained($unit),
                );
            }

            return $body
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
                    . "акаунт розблокується автоматично.\n\n"
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
