<?php

namespace App\EventSubscriber;

use App\Service\TelegramUserService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class RequestSubscriber implements EventSubscriberInterface
{
    private const WEBHOOK_ROUTE = 'app_webhook';

    public function __construct(
        private LoggerInterface $graylogLogger,
        private TelegramUserService $telegramUserService,
        private string $webhookSecret = '',
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            RequestEvent::class => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $this->graylogLogger->info('catch request');

        if (!$event->isMainRequest()) {
            // don't do anything if it's not the main request
            return;
        }

        $request = $event->getRequest();

        // Before anything reads the body: initUser() below rewrites a person's chat_id, the
        // address of all their mail, from whatever the update claims. 403 and not Nutgram's
        // silent 200, because Telegram retries a refused update and forgets an accepted one.
        if (!$this->mayCallWebhook($request)) {
            $event->setResponse(new Response('', Response::HTTP_FORBIDDEN));

            return;
        }

        if ('' === $content = $request->getContent()) {
            return;
        }

        try {
            $content = json_decode($content, true, 512, \JSON_BIGINT_AS_STRING | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return;
        }

        if (!\is_array($content)) {
            return;
        }

        // Only an update from Telegram names a person. Any other url takes no identity from
        // its body: the check above guards /hook and nothing else.
        $from = $request->attributes->get('_route') === self::WEBHOOK_ROUTE
            ? self::privateChatSender($content)
            : null;

        if ($from) {
            $this->telegramUserService->initUser($from);
        }
        $this->graylogLogger->info('Pure request', ['request' => $content]);
    }

    /** Telegram's `secret_token` on /hook. No secret configured means no check. */
    private function mayCallWebhook(Request $request): bool
    {
        if ($this->webhookSecret === '' || $request->attributes->get('_route') !== self::WEBHOOK_ROUTE) {
            return true;
        }

        return hash_equals($this->webhookSecret, (string)$request->headers->get('X-Telegram-Bot-Api-Secret-Token'));
    }

    /**
     * The sender of an update, but only when it came from a one-to-one chat with the bot.
     *
     * `chat_id` is the address of every outgoing notice — debts, photo reminders and
     * blocks, vote notices, the rental phone relay — and initUser() overwrites it from
     * whatever chat the update arrived in. A group update carries the *group's* id in
     * exactly that field, so reading it anywhere but a private chat re-points a
     * resident's personal mail at the group. On 02.09.2026 the single service message
     * "Ivan added the bot" to «ЖК City Park» was enough to do it, before a word had
     * been written there; the next debt notice would have named the amount in front of
     * the neighbours.
     *
     * Group updates therefore create and touch nothing at all. The residents' chat gate
     * works off chat_join_request, which never reaches this method.
     */
    private static function privateChatSender(array $content): ?array
    {
        if (isset($content['message']['from']) && self::isPrivate($content['message']['chat'] ?? null)) {
            $from = $content['message']['from'];
            $from['chat_id'] = $content['message']['chat']['id'];

            return $from;
        }

        $callback = $content['callback_query'] ?? null;

        if (isset($callback['from']) && self::isPrivate($callback['message']['chat'] ?? null)) {
            $from = $callback['from'];
            $from['chat_id'] = $callback['message']['chat']['id'];

            return $from;
        }

        return null;
    }

    private static function isPrivate(mixed $chat): bool
    {
        return is_array($chat)
            && isset($chat['id'])
            && ($chat['type'] ?? null) === 'private';
    }
}
