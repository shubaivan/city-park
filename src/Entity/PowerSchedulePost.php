<?php

namespace App\Entity;

use App\Repository\PowerSchedulePostRepository;
use App\Service\PowerTarget;
use Doctrine\ORM\Mapping as ORM;

/**
 * The post in the «💡 Світло» topic for one day.
 *
 * One per date, and **edited in place** when Черкасиобленерго changes the plan: the
 * company re-posts two or three times a day when Укренерго shifts the hours, and three
 * pictures for one Wednesday leave the reader guessing which is current. Editing has no
 * age limit, unlike deleting.
 *
 * `signature` is what was drawn — our buildings' queues and their windows — so a new
 * channel post that changes some other queue's hours does not touch ours.
 */
#[ORM\Entity(repositoryClass: PowerSchedulePostRepository::class)]
#[ORM\UniqueConstraint(name: 'power_schedule_post_target_day_uniq', columns: ['target', 'day'])]
class PowerSchedulePost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The chat this post stands in: one post per day per chat. */
    #[ORM\Column(length: 16, options: ['default' => PowerTarget::RESIDENTS])]
    private string $target;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    #[ORM\Column(type: 'text')]
    private string $signature;

    /** The channel post it was drawn from (t.me/pat_cherkasyoblenergo/<n>). */
    #[ORM\Column]
    private int $source_post;

    #[ORM\Column(nullable: true)]
    private ?int $message_id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $posted_at;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updated_at = null;

    /**
     * Reminders already said for this day, by key («off@720», «on@840») — the cron runs
     * every two minutes and a reminder is due for several of them. Keyed by the time
     * rather than by position, so a changed schedule gets its own new reminders.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $reminders_sent = [];

    /** The last reminder posted for this day, deleted when the next one goes out. */
    #[ORM\Column(nullable: true)]
    private ?int $reminder_message_id = null;

    public function __construct(\DateTimeImmutable $day, string $signature, int $sourcePost, string $target = PowerTarget::RESIDENTS)
    {
        $this->target = $target;
        $this->day = $day;
        $this->signature = $signature;
        $this->source_post = $sourcePost;
        $this->posted_at = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function getSourcePost(): int
    {
        return $this->source_post;
    }

    public function getMessageId(): ?int
    {
        return $this->message_id;
    }

    public function setMessageId(?int $messageId): static
    {
        $this->message_id = $messageId;

        return $this;
    }

    public function getPostedAt(): \DateTimeImmutable
    {
        return $this->posted_at;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updated_at;
    }

    public function replace(string $signature, int $sourcePost): static
    {
        $this->signature = $signature;
        $this->source_post = $sourcePost;
        $this->updated_at = new \DateTimeImmutable();

        return $this;
    }

    /** A newer channel post that leaves our hours as they were. */
    public function seen(int $sourcePost): static
    {
        $this->source_post = max($this->source_post, $sourcePost);

        return $this;
    }

    public function wasReminded(string $key): bool
    {
        return in_array($key, $this->reminders_sent, true);
    }

    public function markReminded(string $key, ?int $messageId): static
    {
        $this->reminders_sent[] = $key;
        $this->reminder_message_id = $messageId;

        return $this;
    }

    /**
     * The minute of the day emergency outages were applied from, once «Світло» has said so
     * (key «gav@<channel post>@<minute>»), or null. The earliest, if the company posted twice.
     */
    public function emergencySince(): ?int
    {
        $since = null;

        foreach ($this->reminders_sent as $key) {
            if (preg_match('/^gav@\d+@(\d+)$/', $key, $m)) {
                $since = $since === null ? (int)$m[1] : min($since, (int)$m[1]);
            }
        }

        return $since;
    }

    public function getReminderMessageId(): ?int
    {
        return $this->reminder_message_id;
    }

    public function forgetReminderMessage(): static
    {
        $this->reminder_message_id = null;

        return $this;
    }
}
