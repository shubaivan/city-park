<?php

namespace App\Entity;

use App\Repository\TariffRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TariffRepository::class)]
#[ORM\Table(name: 'tariff')]
class Tariff
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price_per_meter = '0';

    #[ORM\Column(type: 'datetime')]
    private \DateTime $updated_at;

    public function __construct()
    {
        $this->updated_at = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPricePerMeter(): string
    {
        return $this->price_per_meter;
    }

    public function setPricePerMeter(string $price): static
    {
        $this->price_per_meter = $price;
        $this->updated_at = new \DateTime();
        return $this;
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updated_at;
    }
}
