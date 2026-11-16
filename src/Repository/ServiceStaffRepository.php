<?php

namespace App\Repository;

use App\Entity\ServiceStaff;
use App\Service\PhoneKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceStaff>
 */
class ServiceStaffRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceStaff::class);
    }

    /** Exact match on the nine-digit key; an empty key matches nothing. */
    public function findByPhone(?string $phone): ?ServiceStaff
    {
        $key = PhoneKey::of($phone);

        return $key === '' ? null : $this->findOneBy(['phone_key' => $key]);
    }

    public function findActiveByTelegramId(?string $telegramId): ?ServiceStaff
    {
        if ($telegramId === null || $telegramId === '') {
            return null;
        }

        return $this->findOneBy(['telegram_id' => $telegramId, 'active' => true]);
    }

    /** @return ServiceStaff[] active cleaning staff the bot can write to (linked by /phone) */
    public function findReachableCleaning(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.active = true')
            ->andWhere('s.role = :role')->setParameter('role', ServiceStaff::ROLE_CLEANING)
            ->andWhere('s.telegram_id IS NOT NULL')
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return ServiceStaff[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['active' => 'DESC', 'id' => 'ASC']);
    }
}
