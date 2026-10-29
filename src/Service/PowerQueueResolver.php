<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Which hourly-outage sub-queue each of our buildings is in, asked of Черкасиобленерго.
 *
 * The channel post says «3.1 12:00 - 14:00» and nothing about addresses, so somebody has
 * to know that Козацька 19 is 3.1 — and the company can move a building to another queue
 * between one winter and the next. The cabinet API behind cherkasyoblenergo.com/off is the
 * only place that answers it: department → city → street → house → the household
 * accounts at that address → the queues of one account. There is no «house → queue» call;
 * the site itself goes through an account, so this does too. **Any account in the
 * building will do** — they are the company's, not the ОСББ's, read from the API by
 * address, and the queue belongs to the feeder, not to the flat.
 *
 * Several accounts are tried per building: on 28.10.2026 the first account at Козацька 19
 * came back with an empty queue list while the eleventh said «3 черга 1 підчерга» like
 * every other building. One empty answer is not a fact about the house.
 *
 * Read-only and unauthenticated. The server sends its leaf certificate without the
 * intermediate, so verification runs against the system bundle plus the intermediate
 * kept in `resources/certs` — never with verification off: this answer decides what the
 * whole house is told about its own electricity.
 */
class PowerQueueResolver
{
    private const URL = 'https://cabinet.cherkasyoblenergo.com/api_new/disconn.php';

    /** «Черкаські міські енергетичні мережі». */
    private const DEPARTMENT_ID = '1';

    private const CITY = 'м. Черкаси';

    private const STREET = 'вул. Козацька';

    /** The five buildings of the ЖК on Козацька, plus 25, which the ОСББ's register numbers 6. */
    public const HOUSES = ['17', '19', '21', '23', '25', '27'];

    /**
     * Buildings the company does not list yet, which follow the queue of the rest.
     *
     * Буд. 25 is not on Черкасиобленерго's balance: the cabinet knows the address and no
     * household account under it, so it can never answer for itself. Аліна confirmed on
     * 29.10.2026 that it is fed with the others and sits in the same queue. It is given
     * that queue only while every building that *did* answer agrees on one — if they ever
     * split, which of them 25 belongs to is a question for a person, and it keeps what it
     * had. The day the company lists it, its own answer wins.
     */
    public const FOLLOWS_THE_REST = ['25'];

    /** Accounts asked per building before it is reported as unknown. */
    private const ACCOUNTS_TRIED = 5;

    private const TIMEOUT = 20;

    private const INTERMEDIATE = '/resources/certs/sectigo-public-server-authentication-ca-dv-r36.pem';

    private const SYSTEM_BUNDLES = [
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/cert.pem',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $projectDir,
    ) {}

    /**
     * Building → «3.1», or null where the company did not say.
     *
     * Throws only when the street itself cannot be found — that is the API having
     * changed, not a building being quiet, and the caller must not read it as «nothing
     * moved».
     *
     * @return array<string, ?string>
     */
    public function resolve(): array
    {
        $city = $this->findId($this->call(['op' => 'city_list', 'dept_id' => self::DEPARTMENT_ID]), self::CITY);
        $street = $city === null ? null : $this->findId(
            $this->call(['op' => 'street_list', 'city_id' => $city, 'search_name' => '']),
            self::STREET,
        );

        if ($street === null) {
            throw new \RuntimeException(sprintf('Черкасиобленерго: не знайдено «%s, %s»', self::CITY, self::STREET));
        }

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Kyiv')))->format('d.m.Y');
        $result = [];

        foreach (self::HOUSES as $house) {
            $result[$house] = null;
            $accounts = $this->call(['op' => 'ls_list_by_addr', 'street_id' => $street, 'house' => $house]);

            foreach (array_slice($accounts, 0, self::ACCOUNTS_TRIED) as $account) {
                $ls = (string)($account['LS'] ?? '');

                if ($ls === '') {
                    continue;
                }

                $answer = $this->call([
                    'op' => 'disconn_by_ls',
                    'disconn_selector' => '2',
                    'n_date' => $today,
                    'k_date' => $today,
                    'abon_ls' => $ls,
                ], list: false);

                $queue = self::queueFrom($answer);

                if ($queue !== null) {
                    $result[$house] = $queue;
                    break;
                }
            }
        }

        return self::fillFollowers($result);
    }

    /**
     * Give each of FOLLOWS_THE_REST the queue the answered buildings share, if they share one.
     *
     * @param array<string, ?string> $result
     * @return array<string, ?string>
     */
    public static function fillFollowers(array $result): array
    {
        $answered = array_unique(array_filter(
            array_diff_key($result, array_flip(self::FOLLOWS_THE_REST)),
            static fn (?string $q) => $q !== null,
        ));

        if (count($answered) !== 1) {
            return $result;
        }

        foreach (self::FOLLOWS_THE_REST as $house) {
            if (array_key_exists($house, $result) && $result[$house] === null) {
                $result[$house] = reset($answered);
            }
        }

        return $result;
    }

    /**
     * «Графік погодинних відключень - 3 черга 1 підчерга» → «3.1».
     *
     * The same answer also names the *emergency* schedule («ГАВ - 9 черга»), which is a
     * different list on different days and must not be mistaken for this one.
     *
     * @param array<mixed> $answer
     */
    public static function queueFrom(array $answer): ?string
    {
        $lines = $answer['DISCONN_QUEUQ'] ?? [];

        foreach (is_array($lines) ? $lines : [] as $line) {
            $info = mb_strtolower((string)(is_array($line) ? ($line['QUEUE_INFO'] ?? '') : $line));

            if (str_contains($info, 'погодинн') && preg_match('/(\d+)\s*черга\s*(\d+)\s*підчерга/u', $info, $m)) {
                return $m[1] . '.' . $m[2];
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $query
     * @return array<mixed>
     */
    private function call(array $query, bool $list = true): array
    {
        $response = $this->httpClient->request('GET', self::URL, [
            'query' => $query,
            'timeout' => self::TIMEOUT,
            'cafile' => $this->caBundle(),
        ]);

        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            return [];
        }

        // PHP's json_encode turns a list with gaps into an object — the API returns both.
        return $list ? array_values($data) : $data;
    }

    /** @param array<mixed> $rows */
    private function findId(array $rows, string $name): ?string
    {
        foreach ($rows as $row) {
            if (is_array($row) && mb_strtolower(trim((string)($row['NAME'] ?? ''))) === mb_strtolower($name)) {
                return (string)$row['ID'];
            }
        }

        return null;
    }

    /**
     * The system bundle with the missing intermediate appended, built once into var/.
     * `cafile` replaces the trust store rather than adding to it, so the intermediate
     * alone would trust nothing else — and the system bundle alone is what fails today.
     */
    private function caBundle(): string
    {
        $target = $this->projectDir . '/var/power-ca-bundle.pem';
        $intermediate = $this->projectDir . self::INTERMEDIATE;

        $system = null;
        foreach (array_merge([(string)ini_get('openssl.cafile'), (string)ini_get('curl.cainfo')], self::SYSTEM_BUNDLES) as $candidate) {
            if ($candidate !== '' && is_readable($candidate)) {
                $system = $candidate;
                break;
            }
        }

        if (is_readable($target) && filemtime($target) >= max((int)@filemtime($intermediate), $system ? (int)@filemtime($system) : 0)) {
            return $target;
        }

        $pem = ($system ? (string)file_get_contents($system) . "\n" : '') . (string)file_get_contents($intermediate);

        if (@file_put_contents($target . '.tmp', $pem) === false || !@rename($target . '.tmp', $target)) {
            $this->logger->warning('power: could not write the CA bundle', ['target' => $target]);

            return $intermediate;
        }

        return $target;
    }
}
