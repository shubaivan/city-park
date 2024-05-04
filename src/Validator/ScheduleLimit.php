<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class ScheduleLimit extends Constraint
{
    public string $message = 'За один день дозволенно не більше трьох годин бронювання';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}