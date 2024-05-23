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

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $is_active = true;

    #[ORM\OneToMany(targetEntity: TelegramUser::class, mappedBy: 'account', cascade: ["persist"])]
    private Collection $users;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->is_active = true;
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

    public function is_active(): bool
    {
        return $this->is_active;
    }

    public function setIsActive(bool $is_active): Account
    {
        $this->is_active = $is_active;

        return $this;
    }
}
