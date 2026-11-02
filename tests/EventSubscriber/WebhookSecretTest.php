<?php

namespace App\Tests\EventSubscriber;

use App\Command\BotWebhookUpdateCommand;
use App\EventSubscriber\RequestSubscriber;
use App\Service\TelegramUserService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Only Telegram may call /hook: a forged update must change nothing, least of all the
 * chat_id of a person it names, which is where all their mail goes.
 */
class WebhookSecretTest extends TestCase
{
    private const SECRET = 'right-secret';

    private const FORGED = [
        'update_id' => 1,
        'message' => [
            'message_id' => 1,
            'from' => ['id' => 471925876, 'first_name' => 'Forged'],
            'chat' => ['id' => 471925876, 'type' => 'private'],
            'text' => '/start',
        ],
    ];

    private function dispatch(string $secret, string $route, ?string $header, bool $expectInit): RequestEvent
    {
        $users = $this->createMock(TelegramUserService::class);
        $users->expects($expectInit ? $this->once() : $this->never())->method('initUser');

        $request = Request::create('/hook', 'POST', content: (string)json_encode(self::FORGED));
        $request->attributes->set('_route', $route);

        if ($header !== null) {
            $request->headers->set('X-Telegram-Bot-Api-Secret-Token', $header);
        }

        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new RequestSubscriber(new NullLogger(), $users, $secret))->onKernelRequest($event);

        return $event;
    }

    public function testNoSecretIsRefusedAndNamesNobody(): void
    {
        $event = $this->dispatch(self::SECRET, 'app_webhook', null, false);

        $this->assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function testAWrongSecretIsRefusedAndNamesNobody(): void
    {
        $event = $this->dispatch(self::SECRET, 'app_webhook', 'wrong', false);

        $this->assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function testTheRightSecretGoesThrough(): void
    {
        $event = $this->dispatch(self::SECRET, 'app_webhook', self::SECRET, true);

        $this->assertNull($event->getResponse());
    }

    public function testWithNoSecretConfiguredNothingIsChecked(): void
    {
        $event = $this->dispatch('', 'app_webhook', null, true);

        $this->assertNull($event->getResponse());
    }

    public function testABodySentToAnyOtherUrlNamesNobody(): void
    {
        $event = $this->dispatch(self::SECRET, 'app_login', null, false);

        $this->assertNull($event->getResponse());
    }

    /** The factory's checking mode only survives if nothing replaces it afterwards. */
    public function testNothingReplacesTheCheckingRunningMode(): void
    {
        $config = (string)file_get_contents(__DIR__ . '/../../config/telegram.php');
        $this->assertStringNotContainsString('$bot->setRunningMode(', $config);

        $yaml = (string)file_get_contents(__DIR__ . '/../../config/packages/nutgram.yaml');
        $this->assertMatchesRegularExpression('/^\s*webhook_secret: /m', $yaml);
    }

    /** @return array<string, array{string, ?string}> */
    public static function secrets(): array
    {
        return [
            'configured' => [self::SECRET, self::SECRET],
            'not configured' => ['', null],
        ];
    }

    /** @dataProvider secrets */
    public function testTheCommandHandsTelegramTheSameSecret(string $configured, ?string $sent): void
    {
        $seen = 'unset';
        $bot = $this->createMock(Nutgram::class);
        $bot->method('setWebhook')->willReturnCallback(function (string $url, $certificate, $ip, $max, $allowed, $drop, $secret) use (&$seen) {
            $seen = $secret;

            return true;
        });

        $tester = new CommandTester(new BotWebhookUpdateCommand($bot, $configured));
        $tester->execute(['url' => 'https://example.test/hook']);

        $this->assertSame($sent, $seen);
    }
}
