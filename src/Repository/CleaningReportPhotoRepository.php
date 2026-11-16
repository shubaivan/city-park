<?php

namespace App\Repository;

use App\Entity\CleaningReportPhoto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CleaningReportPhoto>
 */
class CleaningReportPhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CleaningReportPhoto::class);
    }

    public function findByMessage(string $chatId, int $messageId): ?CleaningReportPhoto
    {
        return $this->findOneBy(['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    /** @return CleaningReportPhoto[] the album's photos still waiting, in the order they were sent */
    public function findUnpostedInGroup(string $mediaGroupId): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.media_group_id = :g')->setParameter('g', $mediaGroupId)
            ->andWhere('p.posted_at IS NULL')
            ->orderBy('p.message_id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
