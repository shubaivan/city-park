<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\AccountStatusLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Records every is_active transition on Account into account_status_log.
 *
 * Why a single auditor: the historical "✋ Вручну адміном / Автоматичних причин не виявлено"
 * label was derived live and went stale once the underlying cause (debt, photo miss) was
 * cleared — so admins couldn't answer "who blocked X and why" after the fact. The auditor
 * pins the cause to the moment of mutation.
 *
 * Callers must invoke this on every place that flips Account::is_active. The caller is
 * responsible for flushing the EM; the auditor only persists the new row.
 */
class AccountStatusAuditor
{
    public function __construct(
        private EntityManagerInterface $em,
        private ?Security $security = null,
    ) {}

    public function log(
        Account $account,
        bool $oldActive,
        bool $newActive,
        string $source,
        ?string $reasonCode = null,
        ?string $reasonText = null,
        ?string $actor = null,
    ): void {
        if ($oldActive === $newActive) {
            return;
        }

        if ($actor === null && $this->security !== null) {
            $user = $this->security->getUser();
            if ($user !== null) {
                $actor = $user->getUserIdentifier();
            }
        }

        $row = (new AccountStatusLog())
            ->setAccount($account)
            ->setOldActive($oldActive)
            ->setNewActive($newActive)
            ->setSource($source)
            ->setReasonCode($reasonCode)
            ->setReasonText($reasonText)
            ->setActorUsername($actor)
            ->setCreatedAt(new \DateTime());

        $this->em->persist($row);
    }
}
