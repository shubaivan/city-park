<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WeatherService
{
    private const LAT = 49.4405128;
    private const LNG = 32.0964188;
    private const TZ = 'Europe/Kyiv';
    private const URL = 'https://api.open-meteo.com/v1/forecast';
    private const FORECAST_HORIZON_DAYS = 15;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {}

    public function getDailyForecastLine(\DateTimeInterface $date): ?string
    {
        $tz = new \DateTimeZone(self::TZ);
        $today = (new \DateTimeImmutable('today', $tz))->setTime(0, 0);
        $target = (new \DateTimeImmutable($date->format('Y-m-d'), $tz))->setTime(0, 0);
        $diffDays = (int) $today->diff($target)->format('%r%a');

        if ($diffDays < 0 || $diffDays > self::FORECAST_HORIZON_DAYS) {
            return null;
        }

        $dateKey = $target->format('Y-m-d');

        try {
            return $this->cache->get('weather_cherkasy_' . $dateKey, function (ItemInterface $item) use ($dateKey) {
                $item->expiresAfter(3600);

                $response = $this->httpClient->request('GET', self::URL, [
                    'query' => [
                        'latitude' => self::LAT,
                        'longitude' => self::LNG,
                        'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum,weather_code',
                        'timezone' => self::TZ,
                        'start_date' => $dateKey,
                        'end_date' => $dateKey,
                    ],
                    'timeout' => 4,
                ]);

                $data = $response->toArray(false);
                if (!isset($data['daily']['temperature_2m_max'][0])) {
                    return null;
                }

                $tMax = (int) round($data['daily']['temperature_2m_max'][0]);
                $tMin = (int) round($data['daily']['temperature_2m_min'][0]);
                $prec = (float) ($data['daily']['precipitation_sum'][0] ?? 0);
                $code = (int) ($data['daily']['weather_code'][0] ?? 0);

                $emoji = $this->codeToEmoji($code);
                $cond = $this->codeToText($code, $prec);
                $signMin = $tMin >= 0 ? '+' : '';
                $signMax = $tMax >= 0 ? '+' : '';

                return $emoji . ' ' . $signMin . $tMin . '°…' . $signMax . $tMax . '°, ' . $cond;
            });
        } catch (\Throwable $e) {
            $this->logger->warning('WeatherService: forecast fetch failed: ' . $e->getMessage());
            return null;
        }
    }

    private function codeToEmoji(int $code): string
    {
        return match (true) {
            $code === 0 => '☀️',
            $code <= 3 => '⛅',
            $code === 45 || $code === 48 => '🌫️',
            $code >= 51 && $code <= 67 => '🌧️',
            $code >= 71 && $code <= 77 => '❄️',
            $code >= 80 && $code <= 82 => '🌦️',
            $code >= 85 && $code <= 86 => '🌨️',
            $code >= 95 => '⛈️',
            default => '🌡️',
        };
    }

    private function codeToText(int $code, float $prec): string
    {
        return match (true) {
            $code === 0 => 'ясно',
            $code <= 3 => 'мінлива хмарність',
            $code === 45 || $code === 48 => 'туман',
            $code >= 51 && $code <= 57 => 'мряка',
            $code >= 61 && $code <= 67 => 'дощ',
            $code >= 71 && $code <= 77 => 'сніг',
            $code >= 80 && $code <= 82 => 'зливи',
            $code >= 85 && $code <= 86 => 'снігопад',
            $code >= 95 => 'гроза',
            default => $prec > 0.1 ? 'можливі опади' : 'без опадів',
        };
    }
}
