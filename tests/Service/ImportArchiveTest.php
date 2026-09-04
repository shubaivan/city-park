<?php

namespace App\Tests\Service;

use App\Service\ImportArchive;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The archive of what the accountant uploaded.
 *
 * Two things matter here and neither is the happy path: the filename must be reconstructible
 * (it is the only record of who uploaded when), and `path()` must refuse anything that did
 * not come out of `store()` — the name arrives over HTTP and the files it addresses contain
 * every flat's arrears.
 */
class ImportArchiveTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/city-park-archive-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectDir));
    }

    private function archive(): ImportArchive
    {
        return new ImportArchive($this->projectDir, new NullLogger());
    }

    private function upload(string $name = 'borg.xlsx'): UploadedFile
    {
        $path = $this->projectDir . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($path, 'spreadsheet bytes');

        return new UploadedFile($path, $name, null, null, true);
    }

    public function testItKeepsTheFileUnderTheKindAndNamesItByMomentAndAdmin(): void
    {
        $archive = $this->archive();
        $stored = $archive->store($this->upload(), ImportArchive::KIND_DEBT, 'alina');

        $this->assertNotNull($stored);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-alina\.xlsx$/', $stored);
        $this->assertFileExists($archive->dir(ImportArchive::KIND_DEBT) . '/' . $stored);

        $recent = $archive->recent(ImportArchive::KIND_DEBT);
        $this->assertCount(1, $recent);
        $this->assertSame('alina', $recent[0]['actor']);
        $this->assertSame(strlen('spreadsheet bytes'), $recent[0]['size']);
    }

    /**
     * copy(), not move(): the caller parses the upload from its own temp path immediately
     * after archiving it, and moving the file out from under PhpSpreadsheet would break the
     * import this archive exists to keep a record of.
     */
    public function testTheUploadItselfIsLeftWhereTheImporterExpectsIt(): void
    {
        $file = $this->upload();
        $original = $file->getPathname();

        $this->archive()->store($file, ImportArchive::KIND_DEBT, 'alina');

        $this->assertFileExists($original);
    }

    public function testNewestFirst(): void
    {
        $archive = $this->archive();
        $dir = $archive->dir(ImportArchive::KIND_DEBT);
        mkdir($dir, 0775, true);

        foreach (['2026-09-02_10-36-05-alina.xlsx', '2026-09-04_08-57-35-alina.xlsx', '2026-09-03_11-08-14-luda_boss.xlsx'] as $name) {
            file_put_contents($dir . '/' . $name, 'x');
        }

        $names = array_column($archive->recent(ImportArchive::KIND_DEBT), 'name');

        $this->assertSame([
            '2026-09-04_08-57-35-alina.xlsx',
            '2026-09-03_11-08-14-luda_boss.xlsx',
            '2026-09-02_10-36-05-alina.xlsx',
        ], $names);
    }

    /**
     * The download route takes this name straight from the URL. Every one of these has to
     * come back null, or /admin/import-archive becomes a reader for any file on the box.
     */
    public function testPathRefusesAnythingItDidNotWrite(): void
    {
        $archive = $this->archive();
        $stored = (string)$archive->store($this->upload(), ImportArchive::KIND_DEBT, 'alina');

        $this->assertNotNull($archive->path(ImportArchive::KIND_DEBT, $stored));

        foreach ([
            '../../../../etc/passwd',
            '..%2F..%2F.env',
            '2026-09-04_08-57-35-alina.xlsx/../../../.env',
            '.env',
            'nope.xlsx',
            '2026-09-04_08-57-35-alina.php',
            '',
        ] as $attempt) {
            $this->assertNull(
                $archive->path(ImportArchive::KIND_DEBT, $attempt),
                sprintf('"%s" must not resolve to a file', $attempt),
            );
        }

        // And an unknown kind is not a directory we will read from either.
        $this->assertNull($archive->path('etc', $stored));
        $this->assertSame([], $archive->recent('etc'));
        $this->assertNull($archive->store($this->upload(), 'etc', 'alina'));
    }

    /**
     * A failed archive must never cost the ОСББ an import that would otherwise have moved
     * 143 accounts — it returns null and the upload carries on.
     */
    public function testItFailsQuietlyRatherThanBreakingTheImport(): void
    {
        $archive = new ImportArchive('/proc/nonexistent-and-unwritable', new NullLogger());

        $this->assertNull($archive->store($this->upload(), ImportArchive::KIND_DEBT, 'alina'));
    }

    public function testAnAdminLoginNeverEscapesIntoTheFilename(): void
    {
        $archive = $this->archive();

        $stored = (string)$archive->store($this->upload(), ImportArchive::KIND_AREA, '../../etc/pa ss wd');

        $this->assertStringNotContainsString('/', $stored);
        $this->assertStringNotContainsString(' ', $stored);
        $this->assertNotNull($archive->path(ImportArchive::KIND_AREA, $stored));
    }
}
