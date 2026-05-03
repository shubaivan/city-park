<?php

namespace App\Validator;

use App\Entity\ScheduledSet;
use App\Repository\ScheduledSetRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ScheduleLimitValidator extends ConstraintValidator
{
    public function __construct(private ScheduledSetRepository $repository) {}

    public function validate(mixed $value, Constraint $constraint)
    {
        if (!$constraint instanceof ScheduleLimit) {
            throw new UnexpectedTypeException($constraint, ScheduleLimit::class);
        }

        if (!$value instanceof ScheduledSet) {
            throw new UnexpectedTypeException($constraint, ScheduledSet::class);
        }

        $account = $value->getTelegramUserId()->getAccount();

        $dayBookings = $this->repository->findByDayForAccount(
            $value->getYear(),
            $value->getMonth(),
            $value->getDay(),
            $account
        );
        if (count($dayBookings) >= 3) {
            $this->context
                ->buildViolation($constraint->messageDay)
                ->setParameters([
                    '%count%' => (string)count($dayBookings),
                    '%list%' => $this->formatBookings($dayBookings, false),
                ])
                ->addViolation();
        }

        $first = (clone $value->getScheduledAt())->modify('first day of this month');
        $first->setTime(0, 0);
        $last = (clone $value->getScheduledAt())->modify('last day of this month');
        $last->setTime(23, 59);

        $monthBookings = $this->repository->findByMonthForAccount($first, $last, $account);
        if (count($monthBookings) >= 12) {
            $this->context
                ->buildViolation($constraint->messageMonth)
                ->setParameters([
                    '%count%' => (string)count($monthBookings),
                    '%list%' => $this->formatBookings($monthBookings, true),
                ])
                ->addViolation();
        }

        $overlap = $this->repository->findOverlapForAccount(
            $value->getYear(),
            $value->getMonth(),
            $value->getDay(),
            $value->getHour(),
            $account,
            $value->getId()
        );
        if ($overlap !== null) {
            $pavilionName = $overlap->getPavilion() === 1 ? 'Перша' : 'Друга';
            $this->context
                ->buildViolation($constraint->messageOverlap)
                ->setParameters([
                    '%pavilion%' => $pavilionName,
                    '%hour%' => str_pad((string)$overlap->getHour(), 2, '0', STR_PAD_LEFT),
                    '%who%' => trim($overlap->getTelegramUserId()->concatNameInfo()),
                ])
                ->addViolation();
        }

        // Forbid 1-hour orphans: a new booking at distance 2 from any existing booking
        // by the same account on the same pavilion/day would leave a single free hour
        // trapped between two of the account's bookings — used to squat extra time.
        $pavilionHours = $this->repository->getBookedHoursForAccountPavilion(
            $value->getPavilion(),
            $value->getYear(),
            $value->getMonth(),
            $value->getDay(),
            $account,
            $value->getId()
        );
        $conflicts = [];
        foreach ($pavilionHours as $h) {
            if (abs($value->getHour() - $h) === 2) {
                $conflicts[] = $h;
            }
        }
        if (count($conflicts) > 0) {
            sort($conflicts);
            $existing = implode(', ', array_map(
                static fn(int $h) => str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00',
                $conflicts
            ));
            $this->context
                ->buildViolation($constraint->messageOrphan)
                ->setParameters([
                    '%hour%' => str_pad((string)$value->getHour(), 2, '0', STR_PAD_LEFT) . ':00',
                    '%existing%' => $existing,
                ])
                ->addViolation();
        }
    }

    /**
     * @param ScheduledSet[] $bookings
     */
    private function formatBookings(array $bookings, bool $includeDate): string
    {
        $lines = [];
        foreach ($bookings as $b) {
            $pav = $b->getPavilion() === 1 ? 'Перша' : 'Друга';
            $hour = str_pad((string)$b->getHour(), 2, '0', STR_PAD_LEFT);
            $who = trim($b->getTelegramUserId()->concatNameInfo());
            $prefix = $includeDate ? $b->getScheduledAt()->format('d.m') . ' ' : '';
            $lines[] = sprintf('%s%s:00 — Альтанка %s (%s)', $prefix, $hour, $pav, $who);
        }

        return implode('; ', $lines);
    }
}