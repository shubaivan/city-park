<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\GuestPass;
use App\Entity\QrScan;
use App\Entity\TelegramUser;
use App\Repository\GuestPassRepository;
use App\Repository\QrScanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Passes for the people a flat lets in, and the log of every code anybody scans.
 *
 * All the judgement of the feature lives here; the Telegram halves only draw it.
 *
 * The shape, and why (09.09.2026, Иван):
 *
 * - **A pass lives for a day, and the day is switched on.** He asked for «только на
 *   сегодня», which is right — a pass that lives a month circulates, and a lost screenshot
 *   stays a key. But minting a fresh code every morning would mean *re-sending the picture
 *   to the бригадир every morning*, and on the third day they stop looking at it. So the
 *   picture is made once, forwarded once, and `activate()` turns it on for today.
 * - **The scan log is not optional.** It was asked for in the same breath as opening the
 *   scan to the house, and it is what makes that defensible: «хто їх пустив і коли вони
 *   заходили» has no other answer anywhere in this system.
 */
class GuestPassService
{
    public function __construct(
        private GuestPassRepository $passes,
        private QrScanRepository $scans,
        private EntityManagerInterface $em,
        private Nutgram $bot,
        private LoggerInterface $logger,
        private string $appSecret = '',
    ) {}

    /**
     * This pass's scans, newest first — the flat's own answer to «коли прийшли робітники».
     *
     * @return \App\Entity\QrScan[]
     */
    public function scansOf(GuestPass $pass, int $limit = 5): array
    {
        return $this->scans->forPass((int)$pass->getId(), $limit);
    }

    /** @return GuestPass[] */
    public function liveFor(Account $account): array
    {
        return $this->passes->liveFor($account);
    }

    /**
     * Room for one more?
     *
     * Checked on the button *and* here: a resident can reach «➕ Новий пропуск» from a
     * keyboard drawn before they created their third somewhere else. Same trap the services
     * board's per-author cap is written around.
     */
    public function mayCreate(?Account $account): bool
    {
        return $account instanceof Account
            && count($this->liveFor($account)) < GuestPass::MAX_PER_ACCOUNT;
    }

    public function create(Account $account, ?TelegramUser $issuedBy, string $label): ?GuestPass
    {
        if (!$this->mayCreate($account)) {
            return null;
        }

        $pass = (new GuestPass())
            ->setAccount($account)
            ->setIssuedBy($issuedBy)
            ->setLabel($label);

        // Switched on the moment it is made: somebody creating a pass is standing next to
        // the crew, not planning for Thursday.
        $pass->setActiveOn(new \DateTime('now', new \DateTimeZone('Europe/Kyiv')));

        $this->em->persist($pass);
        $this->em->flush();

        return $pass;
    }

    /** Turn it on for today. Idempotent: pressing it twice is what people do. */
    public function activate(GuestPass $pass): void
    {
        if ($pass->isRevoked()) {
            return;
        }

        $pass->setActiveOn(new \DateTime('now', new \DateTimeZone('Europe/Kyiv')));
        $this->em->flush();
    }

    /** Dead for good — the picture in somebody's phone stops working. */
    public function revoke(GuestPass $pass): void
    {
        $pass->revoke();
        $this->em->flush();
    }

    /**
     * The payload behind a guest pass: `p-<id>-<signature>`.
     *
     * Signed like the resident's own code, and for the same reason: an id on its own could
     * be guessed, and a guard would then confirm somebody nobody invited. The row is what
     * carries the day and the revocation; the signature is what makes the bot the only
     * thing that can mint one.
     */
    public function mintToken(GuestPass $pass): string
    {
        $id = (int)$pass->getId();

        return sprintf('p-%d-%s', $id, $this->signature($id));
    }

    /** @return int|null the pass id, or null when the payload is not one of ours */
    public function readToken(string $payload): ?int
    {
        if (!preg_match('/^p-(\d{1,10})-([a-f0-9]{12})$/', $payload, $m)) {
            return null;
        }

        $id = (int)$m[1];

        // hash_equals, not ===: a plain comparison leaks how much of a signature was right
        // through how long it took to say no.
        return hash_equals($this->signature($id), $m[2]) ? $id : null;
    }

    public function find(int $id): ?GuestPass
    {
        return $this->passes->find($id);
    }

    private function signature(int $passId): string
    {
        return substr(hash_hmac('sha256', 'guest-pass:' . $passId, $this->appSecret), 0, 12);
    }

    /**
     * Write one scan into the log.
     *
     * **Never fatal.** The person is standing at the gate waiting for an answer; a log that
     * cannot be written must not turn into a refusal. Failures go to the log file instead.
     */
    public function recordScan(
        string $kind,
        string $result,
        ?TelegramUser $scannedBy,
        ?Account $subject,
        ?GuestPass $pass = null,
        bool $byGuard = false,
    ): void {
        try {
            $scan = (new QrScan())
                ->setKind($kind)
                ->setResult($result)
                ->setScannedBy($scannedBy)
                ->setScannedByLabel(self::personLabel($scannedBy, $byGuard))
                ->setSubjectAccount($subject)
                ->setSubjectLabel($subject?->getPlaceLabel() ?? '—')
                ->setGuestPassId($pass?->getId())
                ->setByGuard($byGuard);

            // Asked before the row is written, or the row it is asking about is its own.
            $first = $pass !== null
                && $result === QrScan::RESULT_OK
                && !$this->scans->passWasScannedToday((int)$pass->getId(), $scan->getCreatedAt());

            if ($pass !== null) {
                $pass->countScan($scan->getCreatedAt());
            }

            $this->em->persist($scan);
            $this->em->flush();

            if ($first) {
                $this->notifyArrival($pass, $scan);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('qr scan not recorded', [
                'kind' => $kind,
                'result' => $result,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tell the flat its people are here.
     *
     * The first valid scan of the day is the crew arriving, and that is the fact the host
     * actually wants — «тем самым будет знать когда пришли строители» (Иван). Once a day,
     * because a guard who checks the same people twice at the door has not made them arrive
     * twice, and a second message teaches the resident to mute the first.
     *
     * Who scanned is deliberately not named: «охорона» or «мешканець» is the whole of what
     * the host needs, and naming the neighbour who checked turns a security tool into
     * something people avoid using.
     *
     * Never fatal, and never on the gate's critical path: the person at the door already
     * has their answer by the time this runs.
     */
    private function notifyArrival(GuestPass $pass, QrScan $scan): void
    {
        $chatId = $pass->getIssuedBy()?->getChatId();

        if ($chatId === null || $chatId === '') {
            return;
        }

        try {
            $this->bot->sendMessage(
                text: sprintf(
                    "👷 <b>Ваш пропуск щойно перевірили</b>\n\n%s\n%s о <b>%s</b>\n\n"
                        . '<i>Схоже, ваші люди на місці.</i>',
                    htmlspecialchars($pass->getLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $scan->isByGuard() ? 'Перевірила охорона' : 'Перевірив мешканець',
                    $scan->getCreatedAt()->format('H:i'),
                ),
                chat_id: (int)$chatId,
                parse_mode: ParseMode::HTML,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('guest pass arrival notice failed', [
                'pass_id' => $pass->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * «Ніка (буд. 19, кв. 85)», «Охорона · Сергій» — a snapshot, so the row still reads
     * like a person after the FK is nulled.
     */
    public static function personLabel(?TelegramUser $user, bool $byGuard = false): string
    {
        if ($user === null) {
            return $byGuard ? 'Охорона' : '—';
        }

        $name = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? '')) ?: ('id ' . $user->getTelegramId());
        $where = $user->getAccount()?->getPlaceLabel();

        return trim(sprintf(
            '%s%s%s',
            $byGuard ? 'Охорона · ' : '',
            $name,
            $where === null ? '' : ' (' . $where . ')',
        ));
    }
}
