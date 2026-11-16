<?php

namespace App\Command;

use App\Entity\ServiceStaff;
use App\Repository\ServiceStaffRepository;
use App\Service\CleaningStaffService;
use App\Service\PhoneKey;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The cleaning crew's numbers (service_staff). `add` also re-activates a removed number;
 * `remove` switches it off but keeps the row, so the report history stays readable.
 *
 *   bin/console staff:cleaning list
 *   bin/console staff:cleaning add "+380 67 000 00 01" --label=Клінінг
 *   bin/console staff:cleaning remove "+380 67 000 00 01"
 */
#[AsCommand(name: 'staff:cleaning', description: 'List / add / remove cleaning staff (photo reports to the «🧹 Прибирання» topic)')]
class StaffCleaningCommand extends Command
{
    public function __construct(
        private CleaningStaffService $staff,
        private ServiceStaffRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'list | add | remove')
            ->addArgument('phone', InputArgument::OPTIONAL, 'Phone, any shape (+380…, 0…)')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Signature on the chat post', 'Клінінг')
            ->addOption('by', null, InputOption::VALUE_REQUIRED, 'Who added it', 'console')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string)$input->getArgument('action');
        $phone = (string)$input->getArgument('phone');

        if ($action === 'list') {
            $rows = array_map(static fn (ServiceStaff $s): array => [
                $s->getId(),
                $s->getPhone(),
                $s->getLabel(),
                $s->getRole(),
                $s->isActive() ? 'yes' : 'no',
                $s->getTelegramId() ?? '—',
                $s->getLinkedAt()?->format('d.m.Y H:i') ?? '—',
            ], $this->repository->findAllOrdered());
            $io->table(['id', 'phone', 'label', 'role', 'active', 'telegram_id', 'linked'], $rows);

            return Command::SUCCESS;
        }

        if (!in_array($action, ['add', 'remove'], true) || PhoneKey::of($phone) === '') {
            $io->error('Usage: staff:cleaning list | add <phone> [--label=…] | remove <phone>');

            return Command::INVALID;
        }

        if ($input->getOption('dry-run')) {
            $existing = $this->repository->findByPhone($phone);
            $io->note(sprintf('[dry-run] %s %s (existing row: %s)', $action, $phone, $existing?->getId() ?? 'none'));

            return Command::SUCCESS;
        }

        if ($action === 'add') {
            $row = $this->staff->add($phone, (string)$input->getOption('label'), (string)$input->getOption('by'));
            $io->success(sprintf('Active: #%d %s «%s»', $row->getId(), $row->getPhone(), $row->getLabel()));

            return Command::SUCCESS;
        }

        $row = $this->staff->deactivate($phone);
        if ($row === null) {
            $io->warning('No such number in service_staff.');

            return Command::FAILURE;
        }
        $io->success(sprintf('Deactivated: #%d %s', $row->getId(), $row->getPhone()));

        return Command::SUCCESS;
    }
}
