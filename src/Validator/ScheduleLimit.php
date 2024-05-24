<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class ScheduleLimit extends Constraint
{
    public string $messageDay = 'За один день дозволенно не більше 3 годин бронювання.';
    public string $messageMonth = 'За один поточний календарний місяць дозволенно не більше 12 годин бронювання.';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}