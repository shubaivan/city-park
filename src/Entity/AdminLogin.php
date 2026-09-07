<?php

namespace App\Entity;

use App\Repository\AdminLoginRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One attempt to sign in to the panel — who, when, from where, and whether it worked.
 *
 * The panel holds the whole house: who lives in which flat, every phone the ОСББ has,
 * the arrears. Four people share four logins, one of which was handed over in a Telegram
 * message. Until this existed the only record of anybody opening it was nginx's access
 * log on the server, which nobody reads and which the people who share the panel cannot
 * read at all.
 *
 * **Failed attempts are recorded too, and they are the more interesting half.** A
 * successful login answers «це я заходила вчора?»; a run of failures against `alina`
 * from an address nobody recognises is the only thing here that is actually news. The
 * password itself is never touched — only the login that was typed.
 *
 * Visible to **everyone who can sign in**, deliberately: a log that only the owner reads
 * is an audit trail, and this is not one. It is four colleagues being able to see their
 * own last visit and each other's, which is what makes an entry nobody recognises get
 * mentioned out loud the same day.
 */
#[ORM\Entity(repositoryClass: AdminLoginRepository::class)]
#[ORM\Index(columns: ['at'], name: 'idx_admin_login_at')]
class AdminLogin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The login as it was typed, not a user reference: a failed attempt is usually a name
     * that does not exist, and that name is the whole content of the row.
     */
    #[ORM\Column(length: 180)]
    private string $login = '';

    #[ORM\Column]
    private bool $success = false;

    /** Nullable because a request can arrive without one (a console login, a proxy quirk). */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    /**
     * Trimmed hard: the point is «телефон чи компʼютер», not forensics, and a modern UA
     * string is 150 characters of version numbers.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $agent = null;

    #[ORM\Column]
    private \DateTimeImmutable $at;

    public function __construct()
    {
        $this->at = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function setLogin(string $login): static
    {
        // A typed login is user input and lands on a page the other admins read.
        $this->login = mb_substr(trim($login), 0, 180);

        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function setSuccess(bool $success): static
    {
        $this->success = $success;

        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): static
    {
        $this->ip = $ip !== null ? mb_substr($ip, 0, 45) : null;

        return $this;
    }

    public function getAgent(): ?string
    {
        return $this->agent;
    }

    public function setAgent(?string $agent): static
    {
        $this->agent = $agent !== null && $agent !== '' ? mb_substr($agent, 0, 120) : null;

        return $this;
    }

    public function getAt(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function setAt(\DateTimeImmutable $at): static
    {
        $this->at = $at;

        return $this;
    }

    /**
     * «iPhone», «Android», «Windows» — what the person would say if asked what they were
     * sitting at. The full string stays in the column for the rare case it is needed.
     */
    public function getDevice(): string
    {
        $agent = $this->agent ?? '';

        return match (true) {
            $agent === '' => '—',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => '📱 iPhone',
            str_contains($agent, 'Android') => '📱 Android',
            str_contains($agent, 'Macintosh') => '💻 Mac',
            str_contains($agent, 'Windows') => '💻 Windows',
            str_contains($agent, 'Linux') => '💻 Linux',
            default => '💻',
        };
    }
}
