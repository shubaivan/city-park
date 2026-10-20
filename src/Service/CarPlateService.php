<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\CarPlate;
use App\Entity\TelegramUser;
use App\Repository\CarPlateRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The house's own list of cars, written by the households themselves.
 *
 * All the judgement of «🚗 Мої авто» lives here; the Telegram halves only draw it.
 *
 * - **Who writes:** anybody the bot has linked to an object, for that object. `is_active`
 *   is not consulted — like the resident's QR, a car on this list says the household lives
 *   here, and a debt decides booking, never that.
 * - **Who reads:** the household its own numbers; the guard by looking a number up; the
 *   panel the whole list. **Residents cannot look up each other's cars** — «чия це машина»
 *   asked of 457 people is a different feature from the one this is, and the refusal on a
 *   duplicate is worded so it cannot be used as that lookup either.
 */
class CarPlateService
{
    public const ADDED = 'added';
    public const INVALID = 'invalid';
    public const ALREADY_OURS = 'already_ours';
    public const TAKEN = 'taken';
    public const FULL = 'full';

    public function __construct(
        private CarPlateRepository $plates,
        private EntityManagerInterface $em,
        private GuardService $guard,
    ) {}

    /** @return CarPlate[] */
    public function forAccount(Account $account): array
    {
        return $this->plates->forAccount($account);
    }

    /**
     * Room for one more?
     *
     * Checked on the button *and* in add(): a keyboard drawn before the fifth number was
     * added elsewhere is still tappable.
     */
    public function mayAdd(?Account $account): bool
    {
        return $account instanceof Account
            && count($this->forAccount($account)) < CarPlate::MAX_PER_ACCOUNT;
    }

    /**
     * Write a number down for this object. Returns one of the constants above.
     *
     * A number already on another object is refused **without saying which**: the answer
     * «уже записано за буд. 19, кв. 50» would let anybody in the bot find where any car
     * lives by trying to add it.
     */
    public function add(Account $account, ?TelegramUser $by, string $raw): string
    {
        $key = PlateKey::of($raw);

        if ($key === '') {
            return self::INVALID;
        }

        $existing = $this->plates->findByKey($key);

        if ($existing !== null) {
            return $existing->getAccount()?->getId() === $account->getId()
                ? self::ALREADY_OURS
                : self::TAKEN;
        }

        if (!$this->mayAdd($account)) {
            return self::FULL;
        }

        $plate = (new CarPlate())
            ->setAccount($account)
            ->setAddedBy($by)
            ->setPlate($key);

        try {
            $this->em->persist($plate);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Two people typing the same number in the same second; the index decided.
            return self::TAKEN;
        }

        return self::ADDED;
    }

    /** The plate, if it is this household's. */
    public function own(Account $account, int $id): ?CarPlate
    {
        $plate = $id > 0 ? $this->plates->find($id) : null;

        return $plate !== null && $plate->getAccount()?->getId() === $account->getId()
            ? $plate
            : null;
    }

    /** Where the car stands — set, changed, or cleared with an empty string. */
    public function setSpot(CarPlate $plate, ?string $spot): void
    {
        // «немає» typed in answer to «яке паркомісце?» is the answer, not the space's name.
        $none = ['-', '—', 'немає', 'нема', 'ні', 'нет', 'no'];
        $plate->setParkingSpot(in_array(mb_strtolower(trim((string)$spot)), $none, true) ? null : $spot);
        $this->em->flush();
    }

    public function remove(CarPlate $plate): void
    {
        $this->em->remove($plate);
        $this->em->flush();
    }

    /** The guard's question, and nobody else's in the bot. */
    public function mayLookUp(?TelegramUser $user): bool
    {
        return $this->guard->isGuard($user);
    }

    public function lookUp(string $raw): ?CarPlate
    {
        return $this->plates->findByKey(PlateKey::of($raw));
    }
}
