<?php

namespace App\Service;

class UkDateFormatter
{
    public static function dayName(int $weekday): string
    {
        return match ($weekday) {
            1 => 'Понеділок',
            2 => 'Вівторок',
            3 => 'Середа',
            4 => 'Четвер',
            5 => "П'ятниця",
            6 => 'Субота',
            7 => 'Неділя',
            default => '',
        };
    }

    public static function dayNameShort(int $weekday): string
    {
        return match ($weekday) {
            1 => 'Пнд',
            2 => 'Втр',
            3 => 'Срд',
            4 => 'Чтв',
            5 => 'Птн',
            6 => 'Сбт',
            7 => 'Ндл',
            default => '',
        };
    }

    public static function monthName(int $month): string
    {
        return match ($month) {
            1 => 'Січень',
            2 => 'Лютий',
            3 => 'Березень',
            4 => 'Квітень',
            5 => 'Травень',
            6 => 'Червень',
            7 => 'Липень',
            8 => 'Серпень',
            9 => 'Вересень',
            10 => 'Жовтень',
            11 => 'Листопад',
            12 => 'Грудень',
            default => '',
        };
    }

    public static function monthEmoji(int $month): string
    {
        return match ($month) {
            1 => '❄️',
            2 => '☃️',
            3 => '🌱',
            4 => '🌷',
            5 => '🌿',
            6 => '☀️',
            7 => '🏖️',
            8 => '🌻',
            9 => '🍁',
            10 => '🎃',
            11 => '🍂',
            12 => '🎄',
            default => '',
        };
    }

    public static function hourEmoji(int $hour): string
    {
        return match (true) {
            $hour >= 0 && $hour <= 4 => '🌙',
            $hour >= 5 && $hour <= 8 => '🌅',
            $hour >= 9 && $hour <= 11 => '🌤',
            $hour >= 12 && $hour <= 16 => '☀️',
            $hour >= 17 && $hour <= 20 => '🌇',
            default => '🌃',
        };
    }

    /** "Сбт, 09 🌿 Травень" */
    public static function dayDate(\DateTimeInterface $dt): string
    {
        $weekday = (int) $dt->format('N');
        $month = (int) $dt->format('n');
        return self::dayNameShort($weekday) . ', ' . $dt->format('d') . ' ' . self::monthEmoji($month) . ' ' . self::monthName($month);
    }

    /** "🌇 18:00" */
    public static function time(\DateTimeInterface $dt): string
    {
        $hour = (int) $dt->format('H');
        return self::hourEmoji($hour) . ' ' . $dt->format('H:i');
    }
}
