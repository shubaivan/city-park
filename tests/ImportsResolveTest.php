<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every `use` in `src/` must name something that exists.
 *
 * The same family of bug as `VoteTypeHintsResolveTest`, one step earlier: an import is just
 * a name until something touches it. `php -l` passes, the container lints, the whole suite
 * goes green — and the class is only looked up when the line that uses it actually runs.
 *
 * It shipped on 08.09.2026 in `ServiceRefreshPostsCommand`, which imported
 * `Telegram\Types\Message\ParseMode` where this Nutgram has `Telegram\Properties\ParseMode`.
 * Nothing said so until the command ran against prod and every edit came back «Class ...
 * not found» — with the repair it was written to perform left undone.
 *
 * Cheap and total: a few hundred names, resolved by the autoloader the same way PHP will.
 */
class ImportsResolveTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function imports(): iterable
    {
        $root = dirname(__DIR__) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string)file_get_contents($file->getPathname());
            $short = substr($file->getPathname(), strlen($root) + 1);

            // Plain class imports only: `use function` and `use const` name something the
            // autoloader cannot be asked about, and a group import is not used in here.
            preg_match_all('/^use\s+(?!function\s|const\s)([^\s;]+)(?:\s+as\s+(\S+))?\s*;/m', $source, $m, PREG_SET_ORDER);

            foreach ($m as $import) {
                $class = $import[1];
                $alias = $import[2] ?? substr((string)strrchr('\\' . $class, '\\'), 1);

                // `use Doctrine\ORM\Mapping as ORM;` imports a *namespace*, and there is
                // nothing for the autoloader to answer about it. It is told apart by how
                // the file then writes it: `ORM\Column` rather than `ORM`.
                if (str_contains($source, $alias . '\\')) {
                    continue;
                }

                yield $short . ' → ' . $class => [$short, $class];
            }
        }
    }

    /** @dataProvider imports */
    public function testTheImportedNameExists(string $file, string $class): void
    {
        $this->assertTrue(
            class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class),
            sprintf('%s imports %s, which does not exist — it will fail only when that line runs.', $file, $class),
        );
    }
}
