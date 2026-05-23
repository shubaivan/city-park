<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\AccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class Account
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $account_number = null;

    #[ORM\Column(length: 255)]
    private ?string $apartment_number = null;

    #[ORM\Column(length: 255)]
    private ?string $house_number = null;

    #[ORM\Column(length: 255)]
    private ?string $street = null;

    #[ORM\Column(type: 'boolean', nullable: true, options: ['default' => false])]
    private ?bool $is_active = false;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true, options: ['default' => 0])]
    private ?string $debt = '0';

    /**
     * Admin-linked owner group: when set, this account shares booking limits and
     * debt aggregation with every other account having the same `owner_group_id`.
     * NULL means "ungrouped" (treated as a group of one via getEffectiveGroupId()).
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $owner_group_id = null;

    #[ORM\OneToMany(targetEntity: TelegramUser::class, mappedBy: 'account', cascade: ["persist"])]
    private Collection $users;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->is_active = false;
        $this->debt = '0';
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccountNumber(): ?string
    {
        return $this->account_number;
    }

    public function setAccountNumber(string $account_number): static
    {
        $this->account_number = $account_number;

        return $this;
    }

    public function getApartmentNumber(): ?string
    {
        return $this->apartment_number;
    }

    public function setApartmentNumber(string $apartment_number): static
    {
        $this->apartment_number = $apartment_number;

        return $this;
    }

    public function getHouseNumber(): ?string
    {
        return $this->house_number;
    }

    public function setHouseNumber(string $house_number): static
    {
        $this->house_number = $house_number;

        return $this;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(string $street): static
    {
        $this->street = $street;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function setIsActive(bool $is_active): Account
    {
        $this->is_active = $is_active;

        return $this;
    }

    public function getDebt(): ?string
    {
        return $this->debt;
    }

    public function setDebt(?string $debt): static
    {
        $this->debt = $debt;

        return $this;
    }

    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function getOwnerGroupId(): ?int
    {
        return $this->owner_group_id;
    }

    public function setOwnerGroupId(?int $owner_group_id): static
    {
        $this->owner_group_id = $owner_group_id;

        return $this;
    }

    /**
     * Identifier of the owner group this account participates in.
     * Returns the explicit owner_group_id if set, otherwise the account's own id —
     * so every account is "in a group" (a group of one by default) and aggregation
     * queries can join on this value without special-casing nulls.
     */
    public function getEffectiveGroupId(): int
    {
        return $this->owner_group_id ?? (int)$this->id;
    }

    /**
     * Type digit per ОСББ numbering scheme: queue-entrance-TYPE-NNN encoded in
     * the особовий рахунок (account_number). 0 = apartment, 5 = storage
     * (комірка), 7 = parking. Returns null if account_number is shorter than
     * 3 digits or its 3rd digit isn't a recognised type.
     */
    public function getUnitTypeDigit(): ?int
    {
        $digits = preg_replace('/\D+/', '', (string)$this->account_number);
        if (strlen($digits) < 3) {
            return null;
        }
        $d = (int)$digits[2];
        return in_array($d, [0, 5, 7], true) ? $d : null;
    }

    public function isApartment(): bool
    {
        return $this->getUnitTypeDigit() === 0;
    }

    public function isParking(): bool
    {
        return $this->getUnitTypeDigit() === 7;
    }

    public function isStorage(): bool
    {
        if ($this->getUnitTypeDigit() === 5) {
            return true;
        }
        // Legacy rows where apartment_number is free text like "кладова 12".
        $value = mb_strtolower((string)$this->apartment_number, 'UTF-8');
        foreach (['кладов', 'комірчина', 'комирчина', 'storage'] as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether this account is entitled to book the pavilion based on unit type.
     * Apartments (0) and parking (7) qualify; storage (5) does not — its owners
     * don't pay the yard-maintenance fee. Unparseable legacy rows are allowed
     * unless they match a storage keyword, preserving prior behaviour.
     */
    public function canBookPavilion(): bool
    {
        return !$this->isStorage();
    }
}
