<?php

namespace App\Repository;

use App\Entity\Complaint;
use App\Entity\ComplaintComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComplaintComment>
 */
class ComplaintCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplaintComment::class);
    }

    /**
     * The thread, oldest first — it is a conversation, and a conversation read bottom-up
     * makes no sense.
     *
     * @return ComplaintComment[]
     */
    public function thread(Complaint $complaint, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.complaint = :complaint')
            ->setParameter('complaint', $complaint)
            ->orderBy('c.created_at', 'ASC')
            ->addOrderBy('c.id', 'ASC');

        if ($limit !== null) {
            // The last N, still in reading order: fetch from the end, then reverse.
            $qb->orderBy('c.created_at', 'DESC')->addOrderBy('c.id', 'DESC')->setMaxResults($limit);

            return array_reverse($qb->getQuery()->getResult());
        }

        return $qb->getQuery()->getResult();
    }

    public function countFor(Complaint $complaint): int
    {
        return (int)$this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.complaint = :complaint')
            ->setParameter('complaint', $complaint)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The full threads of several complaints at once, keyed by complaint id.
     *
     * The admin page renders every complaint with its discussion under it; asking per row
     * is how a page of 200 entries becomes 200 queries.
     *
     * @param Complaint[] $complaints
     *
     * @return array<int, ComplaintComment[]> oldest first within each thread
     */
    public function threadsFor(array $complaints): array
    {
        if ($complaints === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.complaint IN (:complaints)')
            ->setParameter('complaints', $complaints)
            ->orderBy('c.created_at', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        $threads = [];

        foreach ($rows as $comment) {
            $threads[(int)$comment->getComplaint()?->getId()][] = $comment;
        }

        return $threads;
    }

    /**
     * How many comments each of these complaints has, keyed by complaint id.
     *
     * The admin table renders every complaint on one page; asking per row is how a table
     * of 200 entries becomes 200 queries.
     *
     * @param Complaint[] $complaints
     *
     * @return array<int, int>
     */
    public function countByComplaint(array $complaints): array
    {
        if ($complaints === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.complaint) AS complaint_id, COUNT(c.id) AS total')
            ->andWhere('c.complaint IN (:complaints)')
            ->setParameter('complaints', $complaints)
            ->groupBy('c.complaint')
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int)$row['complaint_id']] = (int)$row['total'];
        }

        return $counts;
    }
}
