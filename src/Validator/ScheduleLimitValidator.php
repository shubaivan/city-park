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

        $count = $this->repository->countOfSetByParams($value->getYear(), $value->getMonth(), $value->getDay(), $value->getTelegramUserId());
        if ($count >= 3) {
            $this->context->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}