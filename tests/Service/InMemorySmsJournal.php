<?php

namespace App\Tests\Service;

use App\Entity\SmsLog;
use App\Repository\SmsLogRepository;
use App\Service\SmsSender;

final class InMemorySmsJournal extends SmsLogRepository
{
    /** @var list<SmsLog> */
    public array $rows = [];

    public function __construct()
    {
    }

    public function alreadySentToday(string $phoneKey, string $purpose): bool
    {
        foreach ($this->rows as $row) {
            if ($row->getPhoneKey() === $phoneKey && $row->getPurpose() === $purpose
                && (!$row->isFailed() || str_starts_with((string)$row->getError(), SmsSender::OUTCOME_UNKNOWN))) {
                return true;
            }
        }

        return false;
    }

    public function find(mixed $id, $lockMode = null, $lockVersion = null): ?object
    {
        foreach ($this->rows as $row) {
            if ($row->getId() === $id) {
                return $row;
            }
        }

        return null;
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        foreach ($this->rows as $row) {
            if ($row->getRun() === $criteria['run'] && $row->getPhoneKey() === $criteria['phone_key']) {
                return $row;
            }
        }

        return null;
    }
}
