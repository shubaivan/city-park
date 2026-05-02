<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class ScheduleLimit extends Constraint
{
    public string $messageDay = 'За один день дозволенно не більше 3 годин бронювання сумарно по обох павільйонах. Ваш рахунок уже має %count% бронювань на цей день: %list%.';
    public string $messageMonth = 'За один поточний календарний місяць дозволенно не більше 12 годин бронювання сумарно по обох павільйонах. Ваш рахунок уже має %count% бронювань за цей місяць: %list%.';
    public string $messageOverlap = 'На цю годину ваш рахунок вже має бронювання: Альтанка %pavilion% о %hour%:00 (заброньовано: %who%). Не можна бронювати обидва павільйони одночасно.';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}