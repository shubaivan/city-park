<?php

namespace App\Repository;

use App\Entity\CleaningComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CleaningComment>
 */
class CleaningCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CleaningComment::class);
    }

    public function findByChatMessage(int $chatMessageId): ?CleaningComment
    {
        return $this->findOneBy(['chat_message_id' => $chatMessageId]);
    }

    /** @return CleaningComment[] every comment not yet relayed, in chat order */
    public function findUnrelayed(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.relayed_at IS NULL')
            ->orderBy('c.chat_message_id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
