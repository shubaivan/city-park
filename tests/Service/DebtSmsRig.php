<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\SmsLog;
use App\Entity\SmsRun;
use App\Repository\AccountRepository;
use App\Repository\ExpectedResidentRepository;
use App\Service\DebtSmsCampaign;
use App\Service\DebtSmsDelivery;
use App\Service\SmsDeliveryChecker;
use App\Service\SmsSender;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * A campaign and a worker on a real SmsSender, with the journal, the queue and TurboSMS in
 * memory. Nothing here can reach TurboSMS: every HttpClient is a MockHttpClient.
 */
trait DebtSmsRig
{
    private RecordingBus $bus;
    private InMemorySmsRuns $runs;
    private DebtSmsDelivery $delivery;
    private int $transactions = 0;
    /** @var array<int, array<int, string|array>> */
    private array $phonesOnFile = [];
    private ?\Throwable $flushFails = null;
    private int $dailyLimit = SmsSender::DEFAULT_DAILY_LIMIT;

    /** @param list<array{0: Account, 1: list<string>}> $debtors */
    private function campaign(array $debtors, SmsSender $sms): DebtSmsCampaign
    {
        $journal = $this->journal();
        $this->bus = new RecordingBus();
        $this->runs = new InMemorySmsRuns($journal);

        $em = $this->entityManager($journal);

        return new DebtSmsCampaign(...$this->parts($debtors), ...[$sms, new NullLogger(), $this->projectDir, $this->runs, $this->bus, $em, $this->checker($sms, $journal, $em)]);
    }

    /** @param list<array{0: Account, 1: list<string>}> $debtors */
    private function realCampaign(array $debtors, MockHttpClient $http, InMemorySmsJournal $journal, ?LoggerInterface $logger = null): DebtSmsCampaign
    {
        $this->bus = new RecordingBus();
        $this->runs = new InMemorySmsRuns($journal);
        $em = $this->entityManager($journal);

        $sms = new SmsSender($http, $em, $journal, $logger ?? new NullLogger(), 'mock-token', 'CityPark', $this->dailyLimit);
        [$accounts, $expected] = $this->parts($debtors);
        $checks = $this->checker($sms, $journal, $em);
        $campaign = new DebtSmsCampaign($accounts, $expected, $sms, $logger ?? new NullLogger(), $this->projectDir, $this->runs, $this->bus, $em, $checks);

        $this->delivery = new DebtSmsDelivery(
            $campaign,
            $sms,
            $accounts,
            $this->runs,
            $journal,
            $em,
            $this->createMock(ManagerRegistry::class),
            $logger ?? new NullLogger(),
            $checks,
            0,
        );

        return $campaign;
    }

    private function checker(SmsSender $sms, InMemorySmsJournal $journal, EntityManagerInterface $em): SmsDeliveryChecker
    {
        return new SmsDeliveryChecker($sms, $journal, $this->bus, $em, $this->createMock(ManagerRegistry::class), new NullLogger());
    }

    /** What the worker does: every job waiting, once, in order. */
    private function drain(\DateTimeImmutable $now): void
    {
        while ($this->bus->messages !== []) {
            $this->delivery->deliver(array_shift($this->bus->messages), $now);
        }
    }

    private function entityManager(InMemorySmsJournal $journal): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('persist')->willReturnCallback(function (object $row) use ($journal): void {
            if ($row instanceof SmsRun) {
                $this->runs->add($row);
            } elseif ($row instanceof SmsLog && !in_array($row, $journal->rows, true)) {
                if ($this->flushFails !== null) {
                    throw $this->flushFails;
                }
                (new \ReflectionProperty(SmsLog::class, 'id'))->setValue($row, count($journal->rows) + 1);
                $journal->rows[] = $row;
            }
        });
        $em->method('wrapInTransaction')->willReturnCallback(function (callable $work) {
            $this->transactions++;

            return $work();
        });

        return $em;
    }

    /** @return array{AccountRepository, ExpectedResidentRepository} */
    private function parts(array $debtors): array
    {
        $byId = [];
        $this->phonesOnFile = [];
        foreach ($debtors as [$account, $numbers]) {
            $byId[$account->getId()] = $account;
            // A plain number, or a row with who claimed it.
            $this->phonesOnFile[$account->getId()] = array_map(
                static fn (string|array $n): array => is_array($n) ? $n : ['phone' => $n, 'claimedByRole' => null],
                $numbers,
            );
        }

        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('findAllWithOwners')->willReturn(array_column($debtors, 0));
        $accounts->method('find')->willReturnCallback(static fn (mixed $id): ?Account => $byId[$id] ?? null);

        $expected = $this->createMock(ExpectedResidentRepository::class);
        $expected->method('phonesByAccount')->willReturnCallback(fn (): array => $this->phonesOnFile);

        return [$accounts, $expected];
    }

    /**
     * @param list<array{0: string, 1: list<string>}> $rows debt and the object's phones
     *
     * @return list<array{0: Account, 1: list<string>}>
     */
    private function debtors(array $rows): array
    {
        $out = [];
        foreach ($rows as $i => [$debt, $phones]) {
            $flat = (string)(10 + $i);
            $account = (new Account())->setDebt($debt)->setHouseNumber('27')->setApartmentNumber($flat)->setAccountNumber('5200' . $flat);
            (new \ReflectionProperty(Account::class, 'id'))->setValue($account, 100 + $i);
            $out[] = [$account, $phones];
        }

        return $out;
    }

    private function journal(): InMemorySmsJournal
    {
        return new InMemorySmsJournal();
    }
}
