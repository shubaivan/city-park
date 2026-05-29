<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class ScheduleLimit extends Constraint
{
    public string $messageDay = "⚠️ <b>Ліміт на день вичерпано</b>\n\nЗа один день — не більше 3 годин на обох павільйонах сумарно.\n\nНа цей день у вас вже %count% бронювань:\n%list%";
    public string $messageMonth = "⚠️ <b>Місячний ліміт вичерпано</b>\n\nЗа поточний місяць — не більше 12 годин на обох павільйонах сумарно.\n\nЦього місяця у вас вже %count% бронювань:\n%list%";
    public string $messageOverlap = "⚠️ <b>Перетин по часу</b>\n\nНа %hour%:00 у вас вже є бронювання на Альтанці %pavilion% (%who%).\n\nНе можна бронювати обидва павільйони одночасно.";
    public string $messageGap = "⚠️ <b>Тільки години підряд</b>\n\nБронювання о %hour% не межує з вашими бронюваннями на цьому павільйоні (%existing%).\n\nГодини потрібно бронювати лише підряд, без пропусків — наприклад 14:00, 15:00, 16:00.";

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}