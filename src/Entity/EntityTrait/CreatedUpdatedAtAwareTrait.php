<?php

namespace App\Entity\EntityTrait;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/*
 * Add #[ORM\HasLifecycleCallbacks()] and use prePersist and preUpdate methods
 */
trait CreatedUpdatedAtAwareTrait
{
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $created_at;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private \DateTime $updated_at;

    public function getCreatedAt(): \DateTime
    {
        return $this->created_at;
    }

    public function setCreatedAt(\DateTime $created_at): self
    {
        $this->created_at = $created_at;

        return $this;
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updated_at;
    }

    public function setUpdatedAt(\DateTime $updated_at): self
    {
        $this->updated_at = $updated_at;

        return $this;
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        $this->created_at = (new \DateTime())->setTimezone(new \DateTimeZone('Europe/Kyiv'));
        $this->updated_at = (new \DateTime())->setTimezone(new \DateTimeZone('Europe/Kyiv'));
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updated_at = (new \DateTime())->setTimezone(new \DateTimeZone('Europe/Kyiv'));
    }
}
