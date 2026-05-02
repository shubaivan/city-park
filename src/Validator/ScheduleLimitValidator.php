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

        $countByDay = $this->repository->countByDay(
            $value->getYear(),
            $value->getMonth(),
            $value->getDay(),
            $account
        );
        if ($countByDay >= 3) {
            $this->context
                ->buildViolation($constraint->messageDay . ' Кількість ваших бронювань вже ' . $countByDay)
                ->addViolation();
        }

        $first = (clone $value->getScheduledAt())->modify('first day of this month');
        $first->setTime(0, 0);
        $last = (clone $value->getScheduledAt())->modify('last day of this month');
        $last->setTime(23, 59);

        $countByMonth = $this->repository->countByMonth($first, $last, $account);

        if ($countByMonth >= 12) {
            $this->context
                ->buildViolation($constraint->messageMonth . ' Кількість ваших бронювань вже ' . $countByMonth)
                ->addViolation();
        }

        if ($this->repository->existsAtSameHourForAccount(
            $value->getYear(),
            $value->getMonth(),
            $value->getDay(),
            $value->getHour(),
            $account,
            $value->getId()
        )) {
            $this->context
                ->buildViolation($constraint->messageOverlap)
                ->addViolation();
        }
    }
}