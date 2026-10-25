<?php

namespace App\Service;

use App\Entity\BookingLog;
use App\Entity\ScheduledSet;
use App\Entity\TelegramUser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes the booking journal (`BookingLog`).
 *
 * Two halves, because a deletion has to be described *before* it happens — once the row is
 * flushed away Doctrine clears its id — and written only *after* it succeeded, or a removal
 * that failed would be on the record as done.
 */
class BookingLogService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ?LoggerInterface $logger = null,
    ) {}

    /** Describe what is about to happen to this hour. Writes nothing. */
    public static function entry(string $action, ScheduledSet $set, ?TelegramUser $actor, ?string $reason = null): BookingLog
    {
        $account = $set->getTelegramUserId()->getAccount();

        return (new BookingLog())
            ->setAction($action)
            ->setActor($actor)
            ->setActorLabel(self::personLabel($actor))
            ->setAccount($account)
            ->setPlaceLabel($account?->getPlaceLabel() ?? '—')
            ->setPavilion($set->getPavilion())
            ->setScheduledAt($set->getScheduledDateTime())
            ->setScheduledSetId($set->getId())
            ->setReason($reason === null ? null : self::plain($reason));
    }

    /**
     * Write it.
     *
     * **Never fatal.** The resident is waiting for the answer to their tap; a journal that
     * cannot be written must not turn a booking into an error. Failures go to the log file.
     */
    public function save(BookingLog $entry): void
    {
        try {
            $this->em->persist($entry);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger?->warning('booking log not recorded', [
                'action' => $entry->getAction(),
                'scheduled_set_id' => $entry->getScheduledSetId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function personLabel(?TelegramUser $user): string
    {
        if ($user === null) {
            return '—';
        }

        $label = $user->getDisplayName();
        if ($user->getUsername()) {
            $label .= ' @' . $user->getUsername();
        }

        return $label;
    }

    /** The refusal as the resident read it, minus the HTML it was sent with. */
    public static function plain(string $text): string
    {
        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5));
    }
}
