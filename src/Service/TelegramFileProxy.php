<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Hand a file that lives on Telegram to a signed-in browser, through this server.
 *
 * The download url Telegram gives out contains the bot token, so it never reaches the
 * browser: not in a redirect, not in a header, not in an error. An exception from the HTTP
 * client quotes that url, which is why its message is neither shown nor logged here.
 *
 * **This class needs the logger-free client wired in services.yaml.** The framework's
 * `http_client` writes every request's full url to the `http_client` log channel at INFO,
 * and fingers_crossed flushes that buffer on any error, so with it the token ended up in
 * the logs on the first failure. `getFile` goes through Nutgram, whose own failures quote
 * the bot url in their exception; those are not caught-and-logged by this class either.
 *
 * `Range` is passed on and the partial answer passed back, which is what lets the player
 * seek and what Safari insists on. Only the headers named below are forwarded.
 *
 * **Nothing is kept here.** `buffer: false` stops the HTTP client spooling the body to a
 * temp stream, each chunk goes straight out, `no-store` keeps it out of the browser's cache
 * and `X-Accel-Buffering: no` out of nginx's temp files. A listing's video going away means
 * this server has nothing of it left.
 *
 * Telegram refuses `getFile` above 20 MB; the caller decides by the stored size, and a file
 * that fails anyway answers 502 like any other failure here.
 */
class TelegramFileProxy
{
    /** A stream holds a PHP-FPM worker; 20 MB on any working connection is far less than this. */
    private const MAX_SECONDS = 600;

    public function __construct(
        private Nutgram $bot,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {}

    public function stream(string $fileId, string $contentType, ?string $range = null): Response
    {
        try {
            $file = $this->bot->getFile($fileId);
            $url = $file === null ? null : $this->bot->downloadUrl($file);

            if ($url === null) {
                return new Response('', Response::HTTP_BAD_GATEWAY);
            }

            $upstream = $this->httpClient->request('GET', $url, [
                'headers' => array_filter([
                    // Byte offsets in `Range` only mean anything on the unencoded file.
                    'Accept-Encoding' => 'identity',
                    'Range' => $range !== null && preg_match('/^bytes=\d*-\d*$/', $range) === 1 ? $range : null,
                ]),
                'buffer' => false,
                'timeout' => 60,
                'max_duration' => self::MAX_SECONDS,
            ]);

            $status = $upstream->getStatusCode();
            $headers = $upstream->getHeaders(false);
        } catch (\Throwable $e) {
            $this->logger->error('telegram file proxy failed', ['exception' => $e::class]);

            return new Response('', Response::HTTP_BAD_GATEWAY);
        }

        if ($status === Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
            return new Response('', $status, array_filter(['Content-Range' => $headers['content-range'][0] ?? null]));
        }

        if ($status !== Response::HTTP_OK && $status !== Response::HTTP_PARTIAL_CONTENT) {
            $this->logger->error('telegram file proxy got an error answer', ['status' => $status]);

            return new Response('', Response::HTTP_BAD_GATEWAY);
        }

        $forward = array_filter([
            'Content-Type' => $contentType,
            'Content-Length' => $headers['content-length'][0] ?? null,
            'Content-Range' => $headers['content-range'][0] ?? null,
            'Accept-Ranges' => $headers['accept-ranges'][0] ?? null,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Accel-Buffering' => 'no',
        ]);

        $client = $this->httpClient;
        $logger = $this->logger;

        return new StreamedResponse(static function () use ($client, $upstream, $logger): void {
            try {
                foreach ($client->stream($upstream) as $chunk) {
                    echo $chunk->getContent();
                    flush();
                }
            } catch (\Throwable $e) {
                // Headers are gone by now; the player sees a short read and retries.
                $logger->warning('telegram file proxy stopped mid-file', ['exception' => $e::class]);
            }
        }, $status, $forward);
    }
}
