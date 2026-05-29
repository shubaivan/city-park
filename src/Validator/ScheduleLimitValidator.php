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

        $dayBookings = $this->repository->findByDayForOwnerGroup(
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

        $monthBookings = $this->repository->findByMonthForOwnerGroup($first, $last, $account);
        if (count($monthBookings) >= 12) {
            $this->context
                ->buildViolation($constraint->messageMonth)
                ->setParameters([
                    '%count%' => (string)count($monthBookings),
                    '%list%' => $this->formatBookings($monthBookings, true),
                ])
                ->addViolation();
        }

        $overlap = $this->repository->findOverlapForOwnerGroup(
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

        // Bookings on the same pavilion/day must form one unbroken run — no gaps.
        // A new hour is only allowed if it extends the existing block (sits directly
        // before its earliest or after its latest hour). Scattered bookings spread
        // across the day (e.g. 14:00, 16:00, 19:00) are forbidden — hours must be
        // contiguous.
        $pavilionHours = $this->repository->getBookedHoursForOwnerGroupPavilion(
            $value->getPavilion(),
            $value->getYear(),
            $value->getMonth(),
            $value->getDay(),
            $account,
            $value->getId()
        );
        if (count($pavilionHours) > 0) {
            $combined = array_values(array_unique([...$pavilionHours, $value->getHour()]));
            sort($combined);
            $isContiguous = (end($combined) - $combined[0] + 1) === count($combined);
            if (!$isContiguous) {
                sort($pavilionHours);
                $existing = implode(', ', array_map(
                    static fn(int $h) => str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00',
                    $pavilionHours
                ));
                $this->context
                    ->buildViolation($constraint->messageGap)
                    ->setParameters([
                        '%hour%' => str_pad((string)$value->getHour(), 2, '0', STR_PAD_LEFT) . ':00',
                        '%existing%' => $existing,
                    ])
                    ->addViolation();
            }
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
            $when = $includeDate
                ? $b->getScheduledAt()->format('d.m') . ' о ' . $hour . ':00'
                : $hour . ':00';
            $lines[] = '   • ' . $when . ' — Альт. ' . $pav;
        }

        return implode("\n", $lines);
    }
}