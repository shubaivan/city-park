<?php

namespace App\Command;

use App\Entity\Account;
use App\Entity\BlockVoteCampaign;
use App\Repository\BlockVoteCampaignRepository;
use App\Service\BlockVoteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * THROWAWAY self-test for the vote-to-block flow. Not committed. Creates 3 test
 * apartment accounts, runs the full lifecycle on the real DB, asserts, and cleans up.
 */
#[AsCommand(name: 'vote:self-test', description: 'Throwaway functional test of the vote-to-block flow.')]
class VoteSelfTestCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private BlockVoteService $voteService,
        private BlockVoteCampaignRepository $campaignRepository,
    ) {
        parent::__construct();
    }

    private function mkAccount(string $num): Account
    {
        $a = (new Account())
            ->setAccountNumber($num)
            ->setApartmentNumber('TEST-' . $num)
            ->setHouseNumber('1')
            ->setStreet('Test St')
            ->setDebt('0');
        $a->setIsActive(true);
        $this->em->persist($a);
        return $a;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pass = true;
        $check = function (string $name, bool $ok) use (&$pass, $io) {
            $io->writeln(($ok ? '<info>PASS</info>' : '<error>FAIL</error>') . ' — ' . $name);
            $pass = $pass && $ok;
        };

        // 3rd digit 0 => apartment (eligible). Distinct numbers to avoid collisions.
        $cand = $this->mkAccount('990010');
        $v1   = $this->mkAccount('990020');
        $v2   = $this->mkAccount('990030');
        $this->em->flush();

        $check('test apartments are eligible voters', $cand->isApartment() && $v1->isApartment() && $v2->isApartment());

        $campaign = (new BlockVoteCampaign())
            ->setCandidate($cand)
            ->setStatus(BlockVoteCampaign::STATUS_OPEN)
            ->setEligibleCount(5)               // yesNeeded = floor(5*0.30)+1 = 2 (>30%)
            ->setDeadlineAt((new \DateTime('now', new \DateTimeZone('Europe/Kyiv')))->modify('+7 days'));
        $this->em->persist($campaign);
        $this->em->flush();
        $cid = $campaign->getId();

        $check('yesNeeded is >30% threshold (2 of 5)', $campaign->yesNeeded() === 2);

        $r1 = $this->voteService->recordVote($campaign, $v1, true);
        $check('first YES does not pass yet (1/2)', $r1['passed'] === false && $r1['yes'] === 1);

        $campaign = $this->campaignRepository->find($cid);
        $r2 = $this->voteService->recordVote($campaign, $v2, true);
        $check('second YES passes (2/2)', $r2['passed'] === true && $r2['yes'] === 2);

        $this->em->clear();
        $cand = $this->em->getRepository(Account::class)->findOneBy(['account_number' => '990010']);
        $campaign = $this->campaignRepository->find($cid);
        $check('candidate is now blocked', $cand->isActive() === false);
        $check('blocked_until set 30d ahead', $cand->getBlockedUntil() !== null && $cand->isUnderVoteBlock());
        $check('vote_block_count incremented to 1', $cand->getVoteBlockCount() === 1);
        $check('campaign marked passed', $campaign->getStatus() === BlockVoteCampaign::STATUS_PASSED);
        $check('result frozen (yes=2)', $campaign->getResultYes() === 2);

        // Auto-unblock once the window elapses (debt=0, no photo block => access restored).
        $cand->setBlockedUntil((new \DateTime('now', new \DateTimeZone('Europe/Kyiv')))->modify('-1 day'));
        $this->em->flush();
        $unblocked = $this->voteService->autoUnblockExpired();

        $this->em->clear();
        $cand = $this->em->getRepository(Account::class)->findOneBy(['account_number' => '990010']);
        $check('autoUnblockExpired restored access', $unblocked >= 1 && $cand->isActive() === true);
        $check('blocked_until cleared', $cand->getBlockedUntil() === null);
        $check('vote_block_count stays 1 after unblock', $cand->getVoteBlockCount() === 1);

        // Cleanup
        $campaign = $this->campaignRepository->find($cid);
        if ($campaign) {
            foreach ($campaign->getBallots() as $b) {
                $this->em->remove($b);
            }
            $this->em->remove($campaign);
        }
        foreach (['990010', '990020', '990030'] as $num) {
            $acc = $this->em->getRepository(Account::class)->findOneBy(['account_number' => $num]);
            if ($acc) {
                $this->em->remove($acc);
            }
        }
        $this->em->flush();
        $io->writeln('cleanup done');

        if ($pass) {
            $io->success('ALL CHECKS PASSED');
            return Command::SUCCESS;
        }
        $io->error('SOME CHECKS FAILED');
        return Command::FAILURE;
    }
}
