<?php

namespace App\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Nutgram keeps conversation state in the app cache pool, and a deploy must not eat it.
 *
 * Which step of «⏸ Відкласти» a manager is on, half of a complaint somebody is typing —
 * all of it lives in that pool. While it sat under `%kernel.cache_dir%`, every
 * `cache:clear` threw it away, and the failure is invisible from both ends: the prompt
 * arrives, the answer goes nowhere, nothing is logged because nothing threw. It cost an
 * afternoon on 07.09.2026 before the logs said `step: askReason` three times in a row.
 */
class ConversationCacheSurvivesDeployTest extends KernelTestCase
{
    public function testTheAppPoolLivesOutsideTheCacheDirectory(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $cacheDir = realpath($container->getParameter('kernel.cache_dir')) ?: $container->getParameter('kernel.cache_dir');
        $projectDir = $container->getParameter('kernel.project_dir');

        $pool = $container->get('cache.app');
        $pool->save($pool->getItem('conversation_probe')->set('alive'));

        // The adapter does not expose its directory, so the проверка is on the setting
        // that decides it — the one line that cache:clear obeys.
        $config = file_get_contents($projectDir . '/config/packages/cache.yaml');

        $this->assertStringContainsString(
            "directory: '%kernel.project_dir%/var/pools'",
            $config,
            'the app pool must not live under the directory cache:clear deletes',
        );

        $this->assertStringNotContainsString(
            $cacheDir . '/pools',
            $config,
            'never point the app pool back into the cache directory',
        );

        $this->assertTrue($pool->getItem('conversation_probe')->isHit(), 'the pool has to be writable where it now lives');
    }
}
