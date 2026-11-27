<?php

namespace App\Tests\Command;

use App\Command\AreaImportRegistryCommand;
use App\Service\AreaRegistryImport;
use App\Service\ImportArchive;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The console path is now the only way to load the area registry (27.11.2026). */
class AreaImportRegistryCommandTest extends TestCase
{
    private function runCommand(bool $dry, array $rows, bool $expectWrite): CommandTester
    {
        $path = tempnam(sys_get_temp_dir(), 'area_cmd_');

        $import = $this->createMock(AreaRegistryImport::class);
        $import->method('read')->willReturn(['rows' => $rows, 'skipped' => 0, 'sheet' => 'Лист1']);
        $import->expects($rows === [] ? $this->never() : $this->once())->method('apply')->with($rows, $dry)
            ->willReturn(['updated' => 1, 'unchanged' => 0, 'not_found' => [], 'changes' => [['220085', '70.00', '71.40']]]);

        $archive = $this->createMock(ImportArchive::class);
        $archive->expects($expectWrite ? $this->once() : $this->never())->method('store');

        $tester = new CommandTester(new AreaImportRegistryCommand($import, $archive, new NullLogger()));
        $tester->execute(['file' => $path] + ($dry ? ['--dry-run' => true] : []));
        @unlink($path);

        return $tester;
    }

    public function testADryRunReportsAndArchivesNothing(): void
    {
        $tester = $this->runCommand(true, ['220085' => 71.4], false);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('dry run', $tester->getDisplay());
        $this->assertStringContainsString('220085: 70.00 → 71.40', $tester->getDisplay());
    }

    public function testARealRunArchivesTheFile(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runCommand(false, ['220085' => 71.4], true)->getStatusCode());
    }

    public function testAFileWithNoAreaRowsIsRefused(): void
    {
        $this->assertSame(Command::FAILURE, $this->runCommand(false, [], false)->getStatusCode());
    }
}
