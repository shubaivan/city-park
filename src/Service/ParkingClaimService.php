<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\CarPlate;
use App\Repository\AccountRepository;
use App\Repository\ExpectedResidentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * «Це моє власне паркомісце» — the one place the bot joins an object to a household itself.
 *
 * Every other link between a person and an object is the accountant's, and this one is
 * allowed only because it is narrow (Иван, 20.10.2026: «только без спорных»):
 *
 * - **Only an owned space.** A rented one belongs to somebody else's household, and joining
 *   it would put their arrears in this family's header and block this family's booking.
 * - **The household picks the building.** Space numbers repeat — «142» is in буд. 17, 19
 *   and 21 — so the number alone never decides; the reader taps which one is theirs.
 * - **Only a space nobody else holds.** No resident in the bot on it, nobody expected on it
 *   (`ExpectedResident`), not already in another household's group. Anything else is
 *   recorded as a claim for the panel and the reader is told which flat it is under —
 *   building and flat, never a phone — so the two neighbours can sort it out in the chat.
 *
 * What a join changes is real and intended: the space appears in the header with its own
 * debt, and a debt on it blocks the household's booking, like any owner group.
 */
class ParkingClaimService
{
    public const LINKED = 'linked';
    public const ALREADY = 'already';
    public const TAKEN = 'taken';

    public function __construct(
        private AccountRepository $accounts,
        private ExpectedResidentRepository $expected,
        private OwnerGroupService $ownerGroups,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    /**
     * Parking objects whose number is the one typed, in building order.
     *
     * @return Account[]
     */
    public function candidates(CarPlate $plate): array
    {
        $number = $plate->getSpotNumber();

        if ($number === null || $number === '') {
            return [];
        }

        $found = array_values(array_filter(
            $this->accounts->findAll(),
            static fn (Account $a): bool => $a->isParking()
                && ltrim((string)preg_replace('/\D/', '', (string)$a->getApartmentNumber()), '0') === $number,
        ));

        usort($found, static fn (Account $a, Account $b): int => (int)$a->getHouseNumber() <=> (int)$b->getHouseNumber());

        return $found;
    }

    /**
     * Join the picked space to the household, or record why not.
     *
     * @return array{0: string, 1: ?string} the result and, for TAKEN, whose it is
     */
    public function claim(CarPlate $plate, Account $household, Account $parking): array
    {
        $plate->setSpotOwned(true)->setParkingAccount($parking)->setParkingLinked(false);

        $ours = array_map(static fn (Account $a): int => (int)$a->getId(), [$household, ...$this->ownerGroups->siblings($household)]);

        if (in_array((int)$parking->getId(), $ours, true)) {
            $plate->setParkingLinked(true);
            $this->em->flush();

            return [self::ALREADY, null];
        }

        $holder = $this->holder($parking);

        if ($holder !== null) {
            $this->em->flush();
            $this->logger->info('parking claim needs a human', [
                'plate' => $plate->getPlate(),
                'household' => $household->getAccountNumber(),
                'parking' => $parking->getAccountNumber(),
            ]);

            return [self::TAKEN, $holder];
        }

        $error = $this->ownerGroups->link($household, $parking);

        if ($error !== null) {
            $this->em->flush();

            return [self::TAKEN, $parking->getPlaceLabel()];
        }

        $plate->setParkingLinked(true);
        $this->em->flush();

        return [self::LINKED, null];
    }

    /**
     * Whose the space already is, as a place label — or null if nobody's.
     *
     * The flat of the household holding it when there is one, since «паркомісце 142» is no
     * help to somebody looking for its owner in the chat; the space itself otherwise.
     */
    private function holder(Account $parking): ?string
    {
        $group = $parking->getOwnerGroupId() !== null ? $this->ownerGroups->siblings($parking) : [];
        $taken = $group !== []
            || count($parking->getUsers()) > 0
            || $this->expected->count(['account' => $parking, 'claimed_at' => null]) > 0;

        if (!$taken) {
            return null;
        }

        foreach ($group as $sibling) {
            if ($sibling->isApartment()) {
                return $sibling->getPlaceLabel();
            }
        }

        foreach ($parking->getUsers() as $user) {
            if ($user->getAccount() !== null && $user->getAccount()->isApartment()) {
                return $user->getAccount()->getPlaceLabel();
            }
        }

        return $parking->getPlaceLabel();
    }
}
