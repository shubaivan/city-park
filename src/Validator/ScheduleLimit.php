<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class ScheduleLimit extends Constraint
{
    public string $messageDay = 'За один день дозволенно не більше 3 годин бронювання сумарно по обох павільйонах.';
    public string $messageMonth = 'За один поточний календарний місяць дозволенно не більше 12 годин бронювання сумарно по обох павільйонах.';
    public string $messageOverlap = 'У вас вже є бронювання на цю годину в іншому павільйоні. Не можна бронювати обидва павільйони одночасно.';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}