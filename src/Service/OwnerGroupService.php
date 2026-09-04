<?php

namespace App\Service;

use App\Entity\Account;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * "One owner, several objects" — the only place that writes `Account.owner_group_id`.
 *
 * Each property in the house is its own Account: a flat, a parking space and a storage
 * room owned by the same person are three rows with three особові рахунки, because that is
 * how the ОСББ bills them. `owner_group_id` is the admin's statement that some of those
 * rows are in fact one household, and the bot then treats them as one:
 *
 * - booking limits are counted across the group (`ScheduledSetRepository`, five queries
 *   through `COALESCE(owner_group_id, id)`), so a flat + parking owner cannot book 3 hours
 *   twice in a day;
 * - a debt block on any object in the group blocks booking for the whole group
 *   (`DebtPolicy::isOwnerGroupBlocked`), and the message names the object that owes —
 *   debts are deliberately *not* summed, because each object has its own threshold
 *   (area × tariff × 1.5) and summing two would compare a total against half a rule.
 *
 * The logic lived inline in two AdminController JSON endpoints; it moved here when the
 * objects register grew plain-form buttons that do the same thing. Two copies of "which
 * group id survives a merge" is how two pages end up disagreeing about who owns what.
 */
class OwnerGroupService
{
    public function __construct(
        private AccountRepository $accounts,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    /**
     * Put two accounts in one group, merging whatever groups they were already in.
     *
     * @return string|null an error message for the admin, or null on success
     */
    public function link(Account $source, Account $partner): ?string
    {
        if ($source->getId() === $partner->getId()) {
            return 'Не можна прив’язати об’єкт сам до себе.';
        }

        $sourceGid = $source->getOwnerGroupId();
        $partnerGid = $partner->getOwnerGroupId();

        if ($sourceGid !== null && $partnerGid !== null && $sourceGid === $partnerGid) {
            return 'Ці об’єкти вже в одній групі.';
        }

        // Which group id survives: an existing group beats a fresh one, and of two existing
        // groups the smaller id wins — deterministic, so the same merge done twice from two
        // different screens lands on the same answer.
        if ($sourceGid !== null && $partnerGid !== null) {
            $survivor = min($sourceGid, $partnerGid);
            $disappearing = max($sourceGid, $partnerGid);

            foreach ($this->accounts->findBy(['owner_group_id' => $disappearing]) as $account) {
                $account->setOwnerGroupId($survivor);
            }
        } elseif ($sourceGid !== null) {
            $partner->setOwnerGroupId($sourceGid);
        } elseif ($partnerGid !== null) {
            $source->setOwnerGroupId($partnerGid);
        } else {
            $survivor = min((int)$source->getId(), (int)$partner->getId());
            $source->setOwnerGroupId($survivor);
            $partner->setOwnerGroupId($survivor);
        }

        $this->em->flush();

        $this->logger->info('owner group linked', [
            'source' => $source->getAccountNumber(),
            'partner' => $partner->getAccountNumber(),
            'group' => $source->getOwnerGroupId(),
        ]);

        return null;
    }

    /**
     * Take one account out of its group.
     *
     * A group of one is meaningless — `getEffectiveGroupId()` treats it exactly like an
     * ungrouped account — so when unlinking leaves a single member behind, that member is
     * cleared too. Otherwise the panel would show somebody as "grouped" with nobody.
     *
     * @return string|null an error message for the admin, or null on success
     */
    public function unlink(Account $account): ?string
    {
        $groupId = $account->getOwnerGroupId();

        if ($groupId === null) {
            return 'Цей об’єкт і так не в групі.';
        }

        $account->setOwnerGroupId(null);
        $this->em->flush();

        $remaining = $this->accounts->findBy(['owner_group_id' => $groupId]);

        if (count($remaining) === 1) {
            $remaining[0]->setOwnerGroupId(null);
            $this->em->flush();
        } elseif ($remaining !== [] && $groupId === (int)$account->getId()) {
            // The group is numbered after its smallest member, so unlinking that member
            // leaves the rest carrying *this* account's id as their group. Booking limits
            // match on `COALESCE(owner_group_id, id)`, which is still this id — the account
            // we just removed would keep counting against the group it left, and the group
            // against it. Renumber what is left onto its own smallest member.
            $survivor = min(array_map(static fn (Account $a): int => (int)$a->getId(), $remaining));

            foreach ($remaining as $member) {
                $member->setOwnerGroupId($survivor);
            }

            $this->em->flush();
        }

        $this->logger->info('owner group unlinked', [
            'account' => $account->getAccountNumber(),
            'group' => $groupId,
        ]);

        return null;
    }

    /**
     * The other objects of the same owner.
     *
     * @return Account[]
     */
    public function siblings(Account $account): array
    {
        return array_values(array_filter(
            $this->accounts->findGroupSiblings($account),
            static fn (Account $sibling): bool => $sibling->getId() !== $account->getId(),
        ));
    }
}
