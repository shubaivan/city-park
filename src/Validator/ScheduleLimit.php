<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class ScheduleLimit extends Constraint
{
    public string $messageDay = "⚠️ <b>Ліміт на день вичерпано</b>\n\nЗа один день — не більше 3 годин на обох павільйонах сумарно.\n\nНа цей день у вас вже %count% бронювань:\n%list%";
    public string $messageMonth = "⚠️ <b>Місячний ліміт вичерпано</b>\n\nЗа поточний місяць — не більше 12 годин на обох павільйонах сумарно.\n\nЦього місяця у вас вже %count% бронювань:\n%list%";
    public string $messageOverlap = "⚠️ <b>Перетин по часу</b>\n\nНа %hour%:00 у вас вже є бронювання на Альтанці %pavilion% (%who%).\n\nНе можна бронювати обидва павільйони одночасно.";
    public string $messageOrphan = "⚠️ <b>Залишиться порожня година</b>\n\nБронювання о %hour% створить порожню годину між вашими бронюваннями (%existing%).\n\nДозволено бронювати години підряд або не менше 3 годин від існуючих бронювань на цьому павільйоні.";

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}