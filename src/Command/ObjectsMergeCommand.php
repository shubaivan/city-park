<?php

namespace App\Command;

use App\Entity\Account;
use App\Repository\AccountRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fold a mistyped особовий рахунок into the real one.
 *
 * A typo in a рахунок is not a field to correct: by the time anyone notices, the correct
 * account usually exists too — created by the debt file, by the register import or by
 * hand — and months of photo requests, status log entries and bookings have attached
 * themselves to the ghost. Renaming would collide with the unique рахунок, and rightly so.
 *
 * So this moves every reference from the wrong account to the right one and deletes the
 * empty shell. It refuses when both sides carry residents or debt: two accounts that are
 * both alive are not a typo, they are two objects, and merging them would silently move
 * somebody's arrears onto their neighbour.
 *
 * The account is what the debt file matches on, so this is the one operation in the panel
 * that nobody should be able to do by accident — which is why it is a command with a
 * dry-run and not a button.
 */
#[AsCommand(
    name: 'objects:merge',
    description: 'Move everything from a mistyped особовий рахунок onto the correct one and delete the ghost',
)]
class ObjectsMergeCommand extends Command
{
    /**
     * Rows that cannot simply be re-pointed, because a unique key would collide.
     *
     * One photo obligation exists per (account, pavilion, session) and one ballot per
     * (campaign, voter). When both accounts carry the same key, the survivor's row is the
     * one the bot has been working against — reminders sent, blocks counted — so the
     * ghost's copy is dropped rather than merged.
     *
     * @var array<string, array{0: string, 1: string[]}>
     */
    private const UNIQUE_BY = [
        'photo_upload_request' => ['account_id', ['pavilion', 'session_start_at']],
        'block_vote_ballot' => ['voter_account_id', ['campaign_id']],
    ];

    /** Every table that points at an account, and the column it points with. */
    private const REFERENCES = [
        'telegram_user' => 'account_id',
        'account_status_log' => 'account_id',
        'photo_upload_request' => 'account_id',
        'pavilion_photo' => 'account_id',
        'complaint' => 'account_id',
        'rental_listing' => 'account_id',
        'block_vote_campaign' => 'candidate_account_id',
        'block_vote_ballot' => 'voter_account_id',
    ];

    public function __construct(
        private AccountRepository $accounts,
        private EntityManagerInterface $em,
        private Connection $db,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('wrong', InputArgument::REQUIRED, 'The mistyped особовий рахунок (this row is deleted)')
            ->addArgument('right', InputArgument::REQUIRED, 'The correct особовий рахунок (this row survives)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would move and change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool)$input->getOption('dry-run');

        $wrong = $this->accounts->findOneBy(['account_number' => trim((string)$input->getArgument('wrong'))]);
        $right = $this->accounts->findOneBy(['account_number' => trim((string)$input->getArgument('right'))]);

        if (!$wrong instanceof Account || !$right instanceof Account) {
            $io->error('Не знайшов один із рахунків. Обидва мають існувати.');

            return Command::FAILURE;
        }

        if ($wrong->getId() === $right->getId()) {
            $io->error('Це один і той самий рахунок.');

            return Command::FAILURE;
        }

        $io->writeln(sprintf('Зливаю %s (%s) → %s (%s)',
            $wrong->getAccountNumber(), $wrong->getPlaceLabel(),
            $right->getAccountNumber(), $right->getPlaceLabel(),
        ));

        $moves = $this->countReferences((int)$wrong->getId());
        $keeps = $this->countReferences((int)$right->getId());

        // Both alive is not a typo — it is two objects, and folding one into the other
        // would move somebody's arrears onto their neighbour.
        $bothHaveResidents = ($moves['telegram_user'] ?? 0) > 0 && ($keeps['telegram_user'] ?? 0) > 0;
        $bothHaveDebt = (float)$wrong->getDebt() > 0 && (float)$right->getDebt() > 0;

        if ($bothHaveResidents || $bothHaveDebt) {
            $io->error(sprintf(
                'Відмовляюсь: %s. Це схоже на два справжні обʼєкти, а не на друкарську помилку.',
                $bothHaveResidents ? 'на обох рахунках є мешканці' : 'на обох рахунках є борг',
            ));

            return Command::FAILURE;
        }

        if ((float)$wrong->getDebt() > 0) {
            $io->warning(sprintf(
                'На хибному рахунку висить борг %s грн — він зникне разом із рядком. '
                . 'Правильна сума прийде з наступним файлом бухгалтера.',
                $wrong->getDebt(),
            ));
        }

        $moves = array_filter($moves);
        $io->listing($moves === []
            ? ['переносити нічого — рядок порожній']
            : array_map(static fn (string $t, int $n): string => sprintf('%s: %d', $t, $n), array_keys($moves), $moves));

        if ($dry) {
            $io->note('--dry-run: нічого не змінено.');

            return Command::SUCCESS;
        }

        $this->db->beginTransaction();

        try {
            foreach (self::REFERENCES as $table => $column) {
                $dropped = $this->dropCollisions($table, $column, (int)$wrong->getId(), (int)$right->getId());

                if ($dropped > 0) {
                    $io->writeln(sprintf('  %s: %d дубль(і) відкинуто — той самий запис уже є на правильному рахунку', $table, $dropped));
                }

                $this->db->executeStatement(
                    sprintf('UPDATE %s SET %s = :right WHERE %s = :wrong', $table, $column, $column),
                    ['right' => $right->getId(), 'wrong' => $wrong->getId()],
                );
            }

            // A group is keyed by the smallest member's id; the ghost must not stay in one.
            $this->db->executeStatement(
                'UPDATE account SET owner_group_id = NULL WHERE id = :wrong',
                ['wrong' => $wrong->getId()],
            );

            $this->db->executeStatement('DELETE FROM account WHERE id = :wrong', ['wrong' => $wrong->getId()]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $io->error('Не вдалося: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->em->clear();

        $this->logger->warning('accounts merged', [
            'from' => $wrong->getAccountNumber(),
            'to' => $right->getAccountNumber(),
            'moved' => $moves,
        ]);

        $io->success(sprintf('Готово. Рахунок %s більше не існує.', $wrong->getAccountNumber()));

        return Command::SUCCESS;
    }

    /** Delete the ghost's rows whose unique key the survivor already occupies. */
    private function dropCollisions(string $table, string $column, int $wrongId, int $rightId): int
    {
        if (!isset(self::UNIQUE_BY[$table])) {
            return 0;
        }

        [, $keyColumns] = self::UNIQUE_BY[$table];
        $matches = implode(' AND ', array_map(
            static fn (string $c): string => sprintf('ghost.%s = kept.%s', $c, $c),
            $keyColumns,
        ));

        return (int)$this->db->executeStatement(
            sprintf(
                'DELETE FROM %1$s ghost WHERE ghost.%2$s = :wrong AND EXISTS ('
                . 'SELECT 1 FROM %1$s kept WHERE kept.%2$s = :right AND %3$s)',
                $table,
                $column,
                $matches,
            ),
            ['wrong' => $wrongId, 'right' => $rightId],
        );
    }

    /** @return array<string, int> */
    private function countReferences(int $accountId): array
    {
        $counts = [];

        foreach (self::REFERENCES as $table => $column) {
            $counts[$table] = (int)$this->db->fetchOne(
                sprintf('SELECT count(*) FROM %s WHERE %s = :id', $table, $column),
                ['id' => $accountId],
            );
        }

        return $counts;
    }
}
