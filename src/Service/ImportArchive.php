<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Keeps the spreadsheets the accountant uploads.
 *
 * Until this existed, `/admin/debt/upload` read the file straight from PHP's temporary
 * upload path and let it be deleted with the request. Nothing on the server had ever seen
 * it: `account.debt` is overwritten in place, `DebtSnapshot` records only the totals, and
 * `account_status_log` remembers a number only for the accounts that crossed a block
 * threshold that day. So «кинь мені цей файл» could only be answered by the person who
 * uploaded it, out of their own Telegram history.
 *
 * That matters more than it sounds. The debt figures are what the bot blocks people over,
 * what the board publishes and what the residents' chat announces to 77 people — and the
 * only copy of the evidence behind them lived on the accountant's laptop. When the house
 * total moved by 124 UAH overnight, the question "what changed?" had no answer anywhere in
 * the system.
 *
 * Deliberately dumb: files on disk under `var/import-archive/<kind>/`, named by the moment
 * and the admin login, listed newest first. No database table, no retention job — an .xlsx
 * of 172 rows is a few kilobytes and the accountant uploads one a month, so the archive
 * grows by well under a megabyte a year. Retention would be more machinery than the thing
 * it manages.
 */
class ImportArchive
{
    /** Debt figures — uploaded monthly by the accountant. */
    public const KIND_DEBT = 'debt';

    /** The area registry — uploaded rarely, but it is what every debt threshold is built on. */
    public const KIND_AREA = 'area';

    private const KINDS = [self::KIND_DEBT, self::KIND_AREA];

    /** Long enough to be a real archive, short enough that the page stays a page. */
    public const LIST_LIMIT = 24;

    public function __construct(
        private string $projectDir,
        private LoggerInterface $logger,
    ) {}

    /**
     * Copy the upload aside before it is parsed.
     *
     * Never throws and never blocks the import: a failed archive must not cost the ОСББ an
     * import that would otherwise have moved 143 accounts. It is logged and the upload
     * carries on.
     */
    public function store(UploadedFile $file, string $kind, ?string $actor): ?string
    {
        if (!in_array($kind, self::KINDS, true)) {
            return null;
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'xlsx');

        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            $extension = 'xlsx';
        }

        $name = sprintf(
            '%s-%s.%s',
            (new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('Y-m-d_H-i-s'),
            $this->slug($actor),
            $extension,
        );

        try {
            $dir = $this->dir($kind);

            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('cannot create ' . $dir);
            }

            // copy(), not move(): the caller parses the upload from its own temp path right
            // after this, and moving it out from under PhpSpreadsheet would break the import
            // this method exists to keep a record of.
            if (!@copy($file->getPathname(), $dir . '/' . $name)) {
                throw new \RuntimeException('cannot copy into ' . $dir);
            }

            $this->logger->info('import archived', [
                'kind' => $kind,
                'file' => $name,
                'actor' => $actor,
                'original' => $file->getClientOriginalName(),
            ]);

            return $name;
        } catch (\Throwable $e) {
            $this->logger->warning('import archive failed', [
                'kind' => $kind,
                'actor' => $actor,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Newest first.
     *
     * @return array<int, array{name: string, size: int, uploaded_at: \DateTimeImmutable, actor: string}>
     */
    public function recent(string $kind, int $limit = self::LIST_LIMIT): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            return [];
        }

        $dir = $this->dir($kind);

        if (!is_dir($dir)) {
            return [];
        }

        $files = [];

        foreach ((array)scandir($dir) as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (!is_file($path)) {
                continue;
            }

            $files[] = [
                'name' => $entry,
                'size' => (int)filesize($path),
                // The filename carries Kyiv time; mtime is the honest one for sorting and
                // is what a person comparing against their own Telegram history will match.
                'uploaded_at' => (new \DateTimeImmutable('@' . filemtime($path)))
                    ->setTimezone(new \DateTimeZone('Europe/Kyiv')),
                'actor' => $this->actorFromName($entry),
            ];
        }

        usort($files, static fn (array $a, array $b): int => $b['name'] <=> $a['name']);

        return array_slice($files, 0, max(1, $limit));
    }

    /**
     * The absolute path of one archived file, or null.
     *
     * The name arrives over HTTP, so it is matched against a strict pattern and the result
     * is checked to still sit inside the archive directory — basename() alone has been the
     * hole in this shape of code often enough to be worth both.
     */
    public function path(string $kind, string $name): ?string
    {
        if (!in_array($kind, self::KINDS, true)) {
            return null;
        }

        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}-[a-z0-9_\-]+\.(xlsx|xls|csv)$/', $name) !== 1) {
            return null;
        }

        $dir = $this->dir($kind);
        $path = $dir . '/' . $name;
        $real = realpath($path);

        if ($real === false || !str_starts_with($real, (string)realpath($dir))) {
            return null;
        }

        return is_file($real) ? $real : null;
    }

    public function dir(string $kind): string
    {
        return rtrim($this->projectDir, '/') . '/var/import-archive/' . $kind;
    }

    /** Admin logins are ASCII already; this guards the filename against anything else. */
    private function slug(?string $actor): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_\-]+/', '-', (string)$actor) ?? '');
        $slug = trim($slug, '-');

        return $slug === '' ? 'admin' : substr($slug, 0, 40);
    }

    private function actorFromName(string $name): string
    {
        // 2026-09-04_11-57-03-alina.xlsx → alina
        if (preg_match('/^[0-9-]{10}_[0-9-]{8}-(.+)\.[a-z]+$/', $name, $m) === 1) {
            return $m[1];
        }

        return '—';
    }
}
