<?php

namespace App\Service;

/** What a resident is told about a video that arrived where it cannot be used. */
final class RentalVideoHintText
{
    public const OUTSIDE = '🎬 Відео можна додати до свого оголошення: «🔑 Оренда та продаж» → ваше оголошення → «🎬 Додати відео». Потім надішліть його ще раз.';

    public const WHILE_PUBLISHING = '🎬 Відео додається після публікації: на картці оголошення буде кнопка «🎬 Додати відео». Зараз, будь ласка, дайте відповідь на запитання вище.';
}
