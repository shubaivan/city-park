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
    private const FORECAST_DAYS = 16;
    private const HTTP_TIMEOUT = 8;      // idle timeout (s); 4s was too tight for the 16-day payload
    private const OK_TTL = 3600;         // cache a good forecast for an hour
    private const FAIL_TTL = 300;        // negative-cache a failure only briefly so it self-heals

    /** @var array<string, array{line: string, emoji: string}>|null */
    private ?array $memo = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {}

    public function getDailyForecastLine(\DateTimeInterface $date): ?string
    {
        return $this->getForecasts()[$date->format('Y-m-d')]['line'] ?? null;
    }

    public function getDayEmoji(\DateTimeInterface $date): ?string
    {
        return $this->getForecasts()[$date->format('Y-m-d')]['emoji'] ?? null;
    }

    /**
     * @return array<string, array{line: string, emoji: string}>
     */
    private function getForecasts(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $tz = new \DateTimeZone(self::TZ);
        $today = (new \DateTimeImmutable('today', $tz))->format('Y-m-d');
        $cacheKey = 'weather_cherkasy_horizon_' . $today;

        try {
            $this->memo = $this->cache->get($cacheKey, function (ItemInterface $item) {
                // Assume failure: a transient Open-Meteo hiccup (429 rate-limit, 5xx,
                // idle timeout) is negative-cached for only FAIL_TTL so it self-heals
                // within minutes instead of sticking for an hour, while still sparing
                // Open-Meteo a live call on every picker view during the outage. The TTL
                // is promoted to OK_TTL only once we actually hold a forecast.
                $item->expiresAfter(self::FAIL_TTL);

                try {
                    $response = $this->httpClient->request('GET', self::URL, [
                        'query' => [
                            'latitude' => self::LAT,
                            'longitude' => self::LNG,
                            'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum,weather_code',
                            'timezone' => self::TZ,
                            'forecast_days' => self::FORECAST_DAYS,
                        ],
                        'timeout' => self::HTTP_TIMEOUT,
                    ]);

                    // getStatusCode() throws on a transport error (idle timeout, DNS, TLS).
                    // Guard the status explicitly: toArray(false) would otherwise swallow a
                    // 429/5xx and hand back a body with no `daily`, cached as empty.
                    $status = $response->getStatusCode();
                    if ($status < 200 || $status >= 300) {
                        throw new \RuntimeException('Open-Meteo returned HTTP ' . $status);
                    }

                    $data = $response->toArray();
                    $times = $data['daily']['time'] ?? [];
                    if (!$times) {
                        throw new \RuntimeException('Open-Meteo response had no daily.time');
                    }
                } catch (\Throwable $e) {
                    // Negative-cache (FAIL_TTL, set above) and surface the real reason.
                    // In cron this WARNING reaches warm-weather.log via the console handler.
                    $this->logger->warning('WeatherService: forecast fetch failed: ' . $e->getMessage());

                    return [];
                }

                $out = [];
                foreach ($times as $idx => $dateStr) {
                    $tMax = (int) round($data['daily']['temperature_2m_max'][$idx] ?? 0);
                    $tMin = (int) round($data['daily']['temperature_2m_min'][$idx] ?? 0);
                    $prec = (float) ($data['daily']['precipitation_sum'][$idx] ?? 0);
                    $code = (int) ($data['daily']['weather_code'][$idx] ?? 0);

                    $emoji = $this->codeToEmoji($code);
                    $cond = $this->codeToText($code, $prec);
                    $signMin = $tMin >= 0 ? '+' : '';
                    $signMax = $tMax >= 0 ? '+' : '';
                    $line = $emoji . ' ' . $signMin . $tMin . '°…' . $signMax . $tMax . '°, ' . $cond;

                    $out[$dateStr] = [
                        'line' => $line,
                        'emoji' => $emoji,
                    ];
                }

                // Real forecast in hand — safe to keep it for the full hour.
                $item->expiresAfter(self::OK_TTL);

                return $out;
            });
        } catch (\Throwable $e) {
            // Safety net for a failure of the cache backend itself (not the fetch, which
            // is handled inside the callback). Never let a missing weather line bubble up.
            $this->logger->warning('WeatherService: cache lookup failed: ' . $e->getMessage());
            $this->memo = [];
        }

        return $this->memo;
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
