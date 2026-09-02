<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\Complaint;
use App\Entity\TelegramUser;
use App\Repository\ComplaintRepository;
use App\Service\ComplaintService;
use App\Service\ImageStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SergiX44\Nutgram\Nutgram;

/**
 * Who may report a house problem, and who may say it is fixed.
 *
 * The two rules pull in opposite directions and both are easy to "tidy away" later:
 * filing is deliberately open to residents the rest of the bot blocks, while moving a
 * status is deliberately closed to everyone except the head of the ОСББ.
 */
class ComplaintRulesTest extends TestCase
{
    private function service(string $managerIds = ''): ComplaintService
    {
        return new ComplaintService(
            $this->createMock(ComplaintRepository::class),
            $this->createMock(ImageStore::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            $this->createMock(Nutgram::class),
            $managerIds,
        );
    }

    private function account(string $apartment, bool $isActive = true): Account
    {
        $account = (new Account())
            ->setAccountNumber('4100' . $apartment)
            ->setApartmentNumber($apartment)
            ->setHouseNumber('23')
            ->setStreet('Козацька');
        $account->setIsActive($isActive);

        return $account;
    }

    private function user(string $telegramId): TelegramUser
    {
        $user = new TelegramUser();
        $user->setTelegramId($telegramId);

        return $user;
    }

    public function testUnlinkedVisitorCannotFile(): void
    {
        $this->assertFalse($this->service()->mayFile(null));
    }

    /**
     * A debt or a missed pavilion photo flips Account::is_active and blocks *booking*.
     * Someone who owes money is still entitled to report that the lift is broken — and
     * they are paying for that lift. Same call already made for the rental noticeboard
     * and the residents' chat.
     */
    public function testBlockedResidentMayStillFile(): void
    {
        $this->assertTrue($this->service()->mayFile($this->account('45', isActive: false)));
    }

    /**
     * "Ворота в паркінг не відчиняються" is by definition a report from a parking owner,
     * and a storage owner sees the yard just as well. Neither unit type is consulted here:
     * the register is about the building, not about who may book the pavilion.
     */
    public function testParkingAndStorageOwnersMayFile(): void
    {
        $parking = (new Account())
            ->setAccountNumber('317142')
            ->setApartmentNumber('Паркінг 142')
            ->setHouseNumber('21');

        $storage = (new Account())
            ->setAccountNumber('315012')
            ->setApartmentNumber('Комірчина 12')
            ->setHouseNumber('21');

        $this->assertTrue($parking->isParking());
        $this->assertFalse($storage->canBookPavilion());

        $this->assertTrue($this->service()->mayFile($parking));
        $this->assertTrue($this->service()->mayFile($storage));
    }

    public function testOnlyConfiguredManagersMayMoveAStatus(): void
    {
        $service = $this->service('267957704');

        $this->assertTrue($service->isManager($this->user('267957704')));
        $this->assertFalse($service->isManager($this->user('111222333')));
        $this->assertFalse($service->isManager(null));
    }

    public function testSeveralManagersMayBeConfigured(): void
    {
        $service = $this->service(' 267957704 , 111222333 ');

        $this->assertTrue($service->isManager($this->user('267957704')));
        $this->assertTrue($service->isManager($this->user('111222333')));
    }

    /**
     * Nobody configured must mean nobody — not everybody. An empty env var is what a
     * fresh deploy looks like before the id is filled in, and the failure has to be
     * "statuses do not move", never "any resident can close any complaint".
     */
    public function testNobodyIsAManagerWhenTheListIsEmpty(): void
    {
        $service = $this->service('');

        $this->assertFalse($service->isManager($this->user('267957704')));
        $this->assertSame([], $service->managerTelegramIds());
    }

    public function testUnknownStatusIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Complaint())->setStatus('closed_forever');
    }

    public function testStatusChangeStampsWhoAndWhen(): void
    {
        $complaint = new Complaint();
        $before = $complaint->getStatusChangedAt();

        $this->assertTrue($complaint->isOpen());

        $complaint->setStatus(Complaint::STATUS_DONE, 'Людмила Осипенко');

        $this->assertTrue($complaint->isDone());
        $this->assertSame('Людмила Осипенко', $complaint->getStatusChangedBy());
        $this->assertGreaterThanOrEqual($before, $complaint->getStatusChangedAt());
    }

    public function testListLabelIsTruncatedForTheButton(): void
    {
        $service = $this->service();

        $long = (new Complaint())->setText(str_repeat('ліфт ', 40));
        $short = (new Complaint())->setText('Не працює ліфт');

        $this->assertLessThanOrEqual(Complaint::LABEL_MAX, mb_strlen($service->label($long)));
        $this->assertStringEndsWith('…', $service->label($long));
        $this->assertSame('Не працює ліфт', $service->label($short));
    }

    /**
     * Retention is measured from the last thing that happened to the entry, not from when
     * it was filed: a problem reported in January and fixed in June must be kept until
     * July, not purged the day it is closed.
     */
    public function testRetentionRunsFromTheLastStatusChange(): void
    {
        $complaint = new Complaint();

        $filedLongAgo = new \ReflectionProperty(Complaint::class, 'created_at');
        $filedLongAgo->setValue($complaint, new \DateTimeImmutable('-200 days'));

        $complaint->setStatus(Complaint::STATUS_DONE, 'Людмила Осипенко');

        $cutoff = (new \DateTimeImmutable())->modify('-' . Complaint::DONE_RETENTION_DAYS . ' days');

        $this->assertGreaterThan($cutoff, $complaint->getStatusChangedAt());
        $this->assertLessThan($cutoff, $complaint->getCreatedAt());
    }

    public function testTextIsCollapsedAndCapped(): void
    {
        $service = $this->service();

        $this->assertSame('Не працює ліфт', $service->trimText("  Не працює\n\n   ліфт  "));
        $this->assertSame(Complaint::TEXT_MAX, mb_strlen($service->trimText(str_repeat('я', 2000))));
    }
}
