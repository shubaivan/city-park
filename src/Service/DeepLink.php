<?php

namespace App\Service;

use App\Entity\LinkClick;
use App\Entity\TelegramUser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «Відкрити в боті» — the one implementation of a deep link from the residents' chat into
 * the thing the post is about.
 *
 * Three boards post into that chat and all three had the same dead end: the post carries a
 * summary and then asks the reader to leave, open the bot, find the section and find the
 * row they were just reading. This turns that into a tap.
 *
 * **One class rather than three copies**, deliberately: the link, the button, the prefix
 * and the click record have to agree with the router that reads them, and the three
 * photo-upload pages are the standing example in this codebase of what near-twins by copy
 * do over a few months. `DeepLinkTest` pins that every kind here is routed.
 *
 * A **url** button is the only kind that works in a group. The global middleware drops
 * every update arriving from one, so a callback button there spins forever; a `t.me/…`
 * link never sends an update at all.
 */
class DeepLink
{
    /** kind => the prefix that carries it in `/start <payload>`. */
    public const PREFIXES = [
        self::KIND_SERVICE => 's-',
        self::KIND_RENTAL => 'r-',
        self::KIND_COMPLAINT => 'c-',
        self::KIND_DEBT => 'd-',
        self::KIND_VOTE => 'v-',
        self::KIND_GUARD => 'g-',
        self::KIND_PASS => 'p-',
        self::KIND_INFO => 'i-',
    ];

    public const KIND_SERVICE = 'service';
    public const KIND_RENTAL = 'rental';
    public const KIND_COMPLAINT = 'complaint';

    /**
     * The monthly debtors' announcement.
     *
     * The id is the `DebtSnapshot`, not the destination: the board it opens is always the
     * current one, and there is only ever one of those. Carrying the snapshot means the
     * click log answers «which month's post did people actually open», which is the only
     * interesting question about a post that repeats.
     */
    public const KIND_DEBT = 'debt';

    /**
     * A vote of the house. The id is the campaign, so a link forwarded a week later still
     * says which vote it was about even after it has closed.
     */
    public const KIND_VOTE = 'vote';

    /** The guard's QR. Signed, not an id — it is here so the router has one list. */
    public const KIND_GUARD = 'guard';

    /**
     * A flat's pass for its builders. Signed like the guard's, and like it, **not** counted
     * as a click: `/admin/links` answers «did the chat post work», and a pass is nobody's
     * post. Its own scans are logged where they belong, in `QrScan`.
     */
    public const KIND_PASS = 'pass';

    /**
     * One topic of the bot's own instructions — `InfoCommand::LINKABLE` holds the ids.
     *
     * A post that ends «читайте в боті» hands the reader a menu of fifteen topics and asks
     * them to find the one it was about. Recorded like any other link: «did anybody
     * actually read it» is the only interesting question about an announcement.
     */
    public const KIND_INFO = 'info';

    public function __construct(
        private Nutgram $bot,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    /** Which kind a payload belongs to, or null when nothing recognises it. */
    public static function kindOf(string $payload): ?string
    {
        foreach (self::PREFIXES as $kind => $prefix) {
            if (str_starts_with($payload, $prefix)) {
                return $kind;
            }
        }

        return null;
    }

    public static function idOf(string $payload, string $kind): int
    {
        return (int)substr($payload, strlen(self::PREFIXES[$kind] ?? ''));
    }

    /**
     * The button to hang under a chat post.
     *
     * Null when the bot's own username cannot be read — the post still goes out, minus the
     * button, because a missing shortcut must not cost the house the advert.
     */
    public function button(string $kind, ?int $id, string $label = '↗️ Відкрити в боті'): ?InlineKeyboardMarkup
    {
        $url = $this->url($kind, $id);

        return $url === null
            ? null
            : InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make($label, url: $url));
    }

    public function url(string $kind, ?int $id): ?string
    {
        if ($id === null || !isset(self::PREFIXES[$kind])) {
            return null;
        }

        try {
            $username = $this->bot->getMe()?->username;
        } catch (\Throwable) {
            $username = null;
        }

        if ($username === null || $username === '') {
            return null;
        }

        return sprintf('https://t.me/%s?start=%s%d', $username, self::PREFIXES[$kind], $id);
    }

    /**
     * Record that somebody followed a link.
     *
     * **Only links.** Opening the same card from inside the bot writes nothing: the
     * question this answers is «did the chat post work», not «what is this resident
     * reading». That boundary is the whole reason the table is defensible — four admins
     * can read it, and it must not grow into a log of who browses what.
     *
     * Never fatal: a failed write must not cost the resident the page they tapped through
     * to. The click is a measurement; the page is the point.
     */
    public function record(string $kind, int $id, ?TelegramUser $user): void
    {
        if ($id <= 0
            || !isset(self::PREFIXES[$kind])
            || $kind === self::KIND_GUARD
            || $kind === self::KIND_PASS
        ) {
            return;
        }

        try {
            $this->em->persist(
                (new LinkClick())
                    ->setKind($kind)
                    ->setTargetId($id)
                    ->setUser($user)
            );
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('deep link click not recorded', [
                'kind' => $kind,
                'target_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
