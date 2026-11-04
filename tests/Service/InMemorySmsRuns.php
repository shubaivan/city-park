<?php

namespace App\Tests\Service;

use App\Entity\SmsRun;
use App\Repository\SmsRunRepository;

final class InMemorySmsRuns extends SmsRunRepository
{
    /** @var array<int, SmsRun> */
    public array $runs = [];

    public function __construct(private InMemorySmsJournal $journal)
    {
    }

    public function add(SmsRun $run): void
    {
        $id = count($this->runs) + 1;
        (new \ReflectionProperty(SmsRun::class, 'id'))->setValue($run, $id);
        $this->runs[$id] = $run;
    }

    public function find(mixed $id, $lockMode = null, $lockVersion = null): ?object
    {
        return $this->runs[$id] ?? null;
    }

    public function findActive(\DateTimeImmutable $now): ?SmsRun
    {
        foreach ($this->runs as $run) {
            if ($run->isRunning() && $run->isOfTheDay($now)) {
                return $run;
            }
        }

        return null;
    }

    public function closeStale(\DateTimeImmutable $now): int
    {
        $closed = 0;
        foreach ($this->runs as $run) {
            if ($run->isRunning() && !$run->isOfTheDay($now)) {
                $run->stop(SmsRun::REASON_STALE);
                $closed++;
            }
        }

        return $closed;
    }

    public function writtenRows(SmsRun $run): int
    {
        return count(array_filter($this->journal->rows, static fn ($row): bool => $row->getRun() === $run));
    }
}
