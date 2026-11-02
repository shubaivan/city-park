<?php

namespace App\Repository;

use App\Entity\PowerQueue;
use App\Service\PowerTarget;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PowerQueue>
 */
class PowerQueueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PowerQueue::class);
    }

    /** @return array<string, PowerQueue> keyed by building */
    public function byHouse(string $target = PowerTarget::RESIDENTS): array
    {
        $rows = [];

        foreach ($this->findBy(['target' => $target]) as $row) {
            $rows[$row->getHouse()] = $row;
        }

        return $rows;
    }

    /**
     * Building → queue for every building with a known one.
     *
     * @return array<string, string>
     */
    public function known(string $target = PowerTarget::RESIDENTS): array
    {
        $map = [];

        foreach ($this->byHouse($target) as $house => $row) {
            if ($row->getQueue() !== null) {
                $map[$house] = $row->getQueue();
            }
        }

        ksort($map, SORT_NATURAL);

        return $map;
    }
}
