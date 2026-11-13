<?php

namespace App\Tests\Telegram;

use App\Service\GateLogService;
use App\Telegram\Guard\Command\GateEventCommand;
use PHPUnit\Framework\TestCase;

/**
 * An unrouted `gate:` button does not error — it spins, and the guard reads that as «бот не
 * працює» with a car waiting at the barrier. The pattern in config/telegram.php must be the
 * one GateLogService builds its buttons for.
 */
class GateWiringTest extends TestCase
{
    public function testTheConfigRoutesTheServicesPattern(): void
    {
        $config = (string)file_get_contents(__DIR__ . '/../../config/telegram.php');

        $this->assertMatchesRegularExpression(
            '/onCallbackQueryData\(\s*\'' . preg_quote(GateLogService::CALLBACK_PATTERN, '/') . '\',\s*\\\\' . preg_quote(GateEventCommand::class, '/') . '::class/',
            $config,
        );
    }

    public function testEveryButtonTheServiceDrawsIsMatched(): void
    {
        $regex = '/' . GateLogService::CALLBACK_PATTERN . '/';

        $this->assertMatchesRegularExpression($regex, 'gate:in:41:0123456789');
        $this->assertMatchesRegularExpression($regex, 'gate:out:9999999999:abcdefabcd');
        $this->assertMatchesRegularExpression($regex, GateLogService::NOOP_CALLBACK);
        $this->assertDoesNotMatchRegularExpression($regex, 'gate:in:41');
        $this->assertDoesNotMatchRegularExpression($regex, 'gate:up:41:0123456789');
    }
}
