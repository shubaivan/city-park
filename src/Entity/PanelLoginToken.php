<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One of the gate's one-time panel links (PanelLoginLink). Mapped so the schema tooling
 * knows the table; read and written by DoctrinePanelLoginTokenStore with plain SQL, so
 * the single-use burn is one conditional UPDATE.
 *
 * Only the SHA-256 of the token is here — the token exists in the Telegram message alone.
 */
#[ORM\Entity]
#[ORM\Table(name: 'panel_login_token')]
#[ORM\UniqueConstraint(name: 'uniq_panel_login_token_hash', columns: ['token_hash'])]
class PanelLoginToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $token_hash = '';

    #[ORM\Column(length: 32)]
    private string $telegram_id = '';

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $expires_at;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $used_at = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $created_at;

    public function __construct()
    {
        $this->created_at = new \DateTime('now', new \DateTimeZone('Europe/Kyiv'));
        $this->expires_at = $this->created_at;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
