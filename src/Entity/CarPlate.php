<?php

namespace App\Entity;

use App\Repository\CarPlateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A car number a household has said is theirs («🚗 Мої авто», 20.10.2026).
 *
 * The parking discussion in the ЖК kept arriving at the same missing thing: a list of the
 * house's own cars, the «зелений список» any access system would start from. The bot already
 * holds the only verified list of who lives here, so it is the natural place for residents to
 * write their numbers down — **voluntarily**: nothing in the bot depends on a flat having a
 * car on record, and an absent number means nobody wrote it, never that the car is foreign.
 *
 * **It belongs to the object, not the person.** A car is the household's; somebody unlinked
 * from the flat leaves its numbers behind, the way a guest pass stays with the flat that
 * issued it. Who added it is kept beside, SET NULL.
 *
 * **One plate, one object, across the whole table.** The same number on two flats is a
 * question only a human can answer, and a lookup that returns either is a guard told
 * something untrue.
 */
#[ORM\Entity(repositoryClass: CarPlateRepository::class)]
#[ORM\Table(name: 'car_plate')]
#[ORM\Index(name: 'cp_account_idx', columns: ['account_id'])]
#[ORM\UniqueConstraint(name: 'cp_plate_uniq', columns: ['plate'])]
class CarPlate
{
    /**
     * Numbers per object. Two or three cars is a real household; past five it is somebody
     * registering the cars of everyone who visits.
     */
    public const MAX_PER_ACCOUNT = 5;

    public const SPOT_MAX_LENGTH = 40;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Account $account = null;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'added_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TelegramUser $addedBy = null;

    /** `PlateKey::of()` — Latin, no spaces. Never the raw text. */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: false)]
    private string $plate = '';

    /**
     * Where this car stands, as the household wrote it — «138», «45, орендоване».
     *
     * Optional, and per car rather than per flat: a family with two cars often has one on
     * its own space and the other on a rented one (asked for by a resident, 20.10.2026).
     * Free text because that is the shape the answer comes in; plenty of cars here have no
     * space at all and only pull up by the lift to unload.
     */
    #[ORM\Column(type: Types::STRING, length: self::SPOT_MAX_LENGTH, nullable: true)]
    private ?string $parking_spot = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $created_at;

    public function __construct()
    {
        $this->created_at = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getAddedBy(): ?TelegramUser
    {
        return $this->addedBy;
    }

    public function setAddedBy(?TelegramUser $addedBy): static
    {
        $this->addedBy = $addedBy;

        return $this;
    }

    public function getPlate(): string
    {
        return $this->plate;
    }

    public function setPlate(string $key): static
    {
        $this->plate = $key;

        return $this;
    }

    /** «CA 1234 AB». */
    public function getDisplay(): string
    {
        return \App\Service\PlateKey::display($this->plate);
    }

    public function getParkingSpot(): ?string
    {
        return $this->parking_spot;
    }

    /** Trimmed, spaces collapsed, cut to the column; empty means none. */
    public function setParkingSpot(?string $spot): static
    {
        $spot = trim((string)preg_replace('/\s+/u', ' ', (string)$spot));
        $this->parking_spot = $spot === '' ? null : mb_substr($spot, 0, self::SPOT_MAX_LENGTH);

        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }
}
