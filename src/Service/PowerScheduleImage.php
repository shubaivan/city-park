<?php

namespace App\Service;

/**
 * The day's schedule as a picture: a 24-hour strip per queue, dark where there is no
 * light, with the hours of each outage written under it.
 *
 * A picture because it is what people actually read: «06:00 - 08:00, 12:00 - 14:00,
 * 17:00 - 19:00» is three numbers to compare against the clock, while a strip with three
 * dark blocks answers «чи буде світло ввечері» before anybody has read a digit. The text
 * under the picture carries the exact times for whoever needs them.
 *
 * Drawn with GD, which the server already has for the photo boards, and with the font
 * shipped in `resources/fonts` — a font read from `/usr/share/fonts` is a guess about a
 * machine we do not configure, and GD without one draws no text at all.
 */
class PowerScheduleImage
{
    private const WIDTH = 1200;
    private const PAD = 44;
    private const BAR_HEIGHT = 74;

    private const FONT = '/resources/fonts/DejaVuSans.ttf';
    private const FONT_BOLD = '/resources/fonts/DejaVuSans-Bold.ttf';

    public function __construct(private string $projectDir) {}

    /**
     * PNG bytes.
     *
     * @param string $title e.g. «Світло · середа, 29 жовтня»
     * @param list<array{label: string, outages: list<array{int, int}>}> $rows
     */
    public function render(string $title, array $rows, string $footer): string
    {
        $font = $this->projectDir . self::FONT;
        $bold = $this->projectDir . self::FONT_BOLD;

        $rowHeight = 34 + 32 + self::BAR_HEIGHT + 34 + 22;
        $height = self::PAD + 50 + count($rows) * $rowHeight + 20 + self::PAD;

        $img = imagecreatetruecolor(self::WIDTH, $height);
        imageantialias($img, true);

        $bg = imagecolorallocate($img, 255, 255, 255);
        $ink = imagecolorallocate($img, 28, 31, 38);
        $muted = imagecolorallocate($img, 110, 116, 128);
        $on = imagecolorallocate($img, 255, 205, 64);
        $off = imagecolorallocate($img, 44, 49, 61);
        $grid = imagecolorallocate($img, 255, 255, 255);
        $offText = imagecolorallocate($img, 255, 255, 255);

        imagefilledrectangle($img, 0, 0, self::WIDTH, $height, $bg);

        $y = self::PAD + 32;
        imagettftext($img, 28, 0, self::PAD, $y, $ink, $bold, $title);
        $y += 18;

        $barWidth = self::WIDTH - 2 * self::PAD;
        $x0 = self::PAD;
        $px = static fn (int $minute): int => $x0 + (int)round($barWidth * $minute / 1440);

        foreach ($rows as $row) {
            $y += 34;
            imagettftext($img, 19, 0, self::PAD, $y, $ink, $bold, $row['label']);

            $y += 32;
            for ($h = 0; $h < 24; ++$h) {
                $label = sprintf('%02d', $h);
                $cell = $px(($h + 1) * 60) - $px($h * 60);
                $box = imagettfbbox(12, 0, $font, $label);
                imagettftext($img, 12, 0, $px($h * 60) + (int)(($cell - ($box[2] - $box[0])) / 2), $y - 4, $muted, $font, $label);
            }

            $top = $y;
            $bottom = $y + self::BAR_HEIGHT;
            imagefilledrectangle($img, $x0, $top, $x0 + $barWidth, $bottom, $on);

            foreach ($row['outages'] as [$from, $to]) {
                imagefilledrectangle($img, $px($from), $top, $px($to) - 1, $bottom, $off);
            }

            imagesetthickness($img, 2);
            for ($h = 1; $h < 24; ++$h) {
                imageline($img, $px($h * 60), $top, $px($h * 60), $bottom, $grid);
            }
            imagesetthickness($img, 1);

            foreach ($row['outages'] as [$from, $to]) {
                $text = self::clock($from) . '–' . self::clock($to);
                $box = imagettfbbox(15, 0, $bold, $text);
                $w = $box[2] - $box[0];
                $mid = (int)(($px($from) + $px($to)) / 2);
                $tx = max($x0, min($x0 + $barWidth - $w, $mid - (int)($w / 2)));

                // Inside the block when it fits, under it when it does not — an hour is
                // 46 px and «06:00–07:00» is wider than that.
                if ($px($to) - $px($from) >= $w + 12) {
                    imagettftext($img, 15, 0, $tx, $top + (int)(self::BAR_HEIGHT / 2) + 7, $offText, $bold, $text);
                } else {
                    imagettftext($img, 14, 0, $tx, $bottom + 24, $ink, $bold, $text);
                }
            }

            $y = $bottom + 34 + 22;
        }

        $y += 12;
        imagefilledrectangle($img, self::PAD, $y - 16, self::PAD + 22, $y + 4, $on);
        imagettftext($img, 15, 0, self::PAD + 32, $y + 2, $ink, $font, 'є світло');
        imagefilledrectangle($img, self::PAD + 170, $y - 16, self::PAD + 192, $y + 4, $off);
        imagettftext($img, 15, 0, self::PAD + 202, $y + 2, $ink, $font, 'немає світла');

        $box = imagettfbbox(13, 0, $font, $footer);
        imagettftext($img, 13, 0, self::WIDTH - self::PAD - ($box[2] - $box[0]), $y + 2, $muted, $font, $footer);

        ob_start();
        imagepng($img, null, 6);
        imagedestroy($img);

        return (string)ob_get_clean();
    }

    /** 1020 → «17:00»; the end of the day stays «24:00», as the company writes it. */
    public static function clock(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
}
