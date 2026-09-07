<?php

namespace App\Tests\Telegram;

use PHPUnit\Framework\TestCase;

/**
 * Every service the main menu resolves at render time must be public.
 *
 * `StartCommand` is static: it reaches its collaborators through
 * `$bot->getContainer()->get(...)`, which delegates to `@service_container` and therefore
 * only exposes **public** services. A private one is inlined away at compile time and the
 * lookup throws — and each of these lookups is wrapped in `catch (\Throwable) { return
 * false; }`, which is right for a decoration and is exactly why the failure is silent.
 *
 * That is not hypothetical. The guard's two buttons shipped on 07.09.2026 and simply did
 * not appear: no error, nothing in any log, the menu just rendered without them. Nothing
 * distinguishes it from «ще не задеплоїли».
 */
class MenuContainerLookupsTest extends TestCase
{
    public function testEveryServiceTheMenuResolvesIsPublic(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Telegram/Start/Command/StartCommand.php');
        $config = file_get_contents(__DIR__ . '/../../config/services.yaml');

        preg_match_all('/getContainer\(\)->get\((\w+)::class\)/', $source, $matches);
        $this->assertNotEmpty($matches[1], 'no container lookups found — did StartCommand change shape?');

        // Short name → FQN, read from the file's own imports, so a moved class is followed.
        preg_match_all('/^use (App\\\\[\w\\\\]+);$/m', $source, $imports);
        $fqn = [];

        foreach ($imports[1] as $import) {
            $fqn[substr($import, strrpos($import, '\\') + 1)] = $import;
        }

        foreach (array_unique($matches[1]) as $short) {
            $class = $fqn[$short] ?? null;
            $this->assertNotNull($class, sprintf('StartCommand resolves %s but does not import it', $short));

            $this->assertMatchesRegularExpression(
                '/' . preg_quote($class, '/') . ':\s*\n\s*public: true/',
                $config,
                sprintf(
                    '%s is resolved from the bot container by StartCommand, so it must be '
                        . 'declared `public: true` in config/services.yaml. Private services are '
                        . 'inlined at compile time and the lookup throws into a catch that hides it: '
                        . 'the button just never appears.',
                    $class,
                ),
            );
        }
    }
}
