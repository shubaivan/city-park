<?php

namespace App\Entity;

use App\Entity\EntityTrait\CreatedUpdatedAtAwareTrait;
use App\Repository\BlockVoteBallotRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One account's single vote in a campaign. The unique (campaign, voter_account)
 * constraint enforces "one особовий рахунок = one vote": whichever family member
 * casts it owns the account's ballot, and the value can be flipped until the
 * deadline (latest choice wins).
 */
#[ORM\Entity(repositoryClass: BlockVoteBallotRepository::class)]
#[ORM\Table(name: 'block_vote_ballot')]
#[ORM\UniqueConstraint(name: 'bvb_campaign_voter_uniq', columns: ['campaign_id', 'voter_account_id'])]
#[ORM\HasLifecycleCallbacks()]
class BlockVoteBallot
{
    use CreatedUpdatedAtAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BlockVoteCampaign::class, inversedBy: 'ballots')]
    #[ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private BlockVoteCampaign $campaign;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'voter_account_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Account $voterAccount;

    /** true = FOR blocking, false = AGAINST. */
    #[ORM\Column(type: 'boolean', nullable: false)]
    private bool $value;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): BlockVoteCampaign
    {
        return $this->campaign;
    }

    public function setCampaign(BlockVoteCampaign $campaign): self
    {
        $this->campaign = $campaign;
        return $this;
    }

    public function getVoterAccount(): Account
    {
        return $this->voterAccount;
    }

    public function setVoterAccount(Account $voterAccount): self
    {
        $this->voterAccount = $voterAccount;
        return $this;
    }

    public function getValue(): bool
    {
        return $this->value;
    }

    public function setValue(bool $value): self
    {
        $this->value = $value;
        return $this;
    }
}
