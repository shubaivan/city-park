<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\Complaint;
use App\Entity\TelegramUser;
use App\Repository\ComplaintCommentRepository;
use App\Repository\ComplaintRepository;
use App\Repository\TelegramUserRepository;
use App\Service\ComplaintService;
use App\Service\ImageStore;
use App\Service\ResidentChatService;
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
            $this->createMock(ComplaintCommentRepository::class),
            $this->createMock(ImageStore::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            $this->createMock(Nutgram::class),
            $this->residentChat(),
            $this->createMock(TelegramUserRepository::class),
            $managerIds,
        );
    }

    private function residentChat(): ResidentChatService
    {
        $chat = $this->createMock(ResidentChatService::class);
        $chat->method('isConfigured')->willReturn(false);

        return $chat;
    }

    private function account(string $apartment, bool $isActive = true, ?int $id = null): Account
    {
        $account = (new Account())
            ->setAccountNumber('4100' . $apartment)
            ->setApartmentNumber($apartment)
            ->setHouseNumber('23')
            ->setStreet('Козацька');
        $account->setIsActive($isActive);

        if ($id !== null) {
            // Doctrine fills this in production; the comment rules compare accounts by id,
            // and two unsaved rows both answering null is not a match anyone means.
            (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);
        }

        return $account;
    }

    /** Doctrine hydrates every column; a bare `new TelegramUser()` leaves them unset. */
    private function contactUser(?string $username, ?string $phone): TelegramUser
    {
        $user = new TelegramUser();
        $user->setUsername($username);
        $user->setPhoneNumber($phone);
        $user->setFirstName(null);
        $user->setLastName(null);

        return $user;
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

    #############
    # ⏸ Відкладено
    #############

    /**
     * A hold with no reason reads, from the resident's side, as the ОСББ giving up in
     * public — worse than leaving it «в роботі». The reason is enforced in the service and
     * not only in the conversation that asks for it, because /admin/complaints reaches the
     * same method by a different road.
     */
    public function testAHoldWithoutAReasonIsRefused(): void
    {
        $complaint = (new Complaint())->setText('Не працює ліфт');

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->changeStatus($complaint, Complaint::STATUS_ON_HOLD, 'luda_boss');
    }

    public function testAHoldKeepsItsReasonAndStaysOpen(): void
    {
        $complaint = (new Complaint())->setText('Не працює ліфт');

        $this->service()->changeStatus(
            $complaint,
            Complaint::STATUS_ON_HOLD,
            'luda_boss',
            note: 'чекаємо трос, буде за два тижні',
        );

        $this->assertTrue($complaint->isOnHold());
        // A held complaint is still an unsolved problem: it counts as open in the menu
        // badge, in the list ordering and in the cleanup rules.
        $this->assertTrue($complaint->isOpen());
        $this->assertSame('чекаємо трос, буде за два тижні', $complaint->getResolution());
    }

    /**
     * Closing a held complaint with nothing new to say must not publish the hold reason as
     * the "що зробили" note: «✅ Виконано — чекаємо трос із Польщі» is sent to the author
     * and posted in the residents' chat, and it says the opposite of what happened.
     */
    public function testLeavingAHoldClearsTheStaleReason(): void
    {
        $service = $this->service();
        $complaint = (new Complaint())->setText('Не працює ліфт');

        $service->changeStatus($complaint, Complaint::STATUS_ON_HOLD, 'luda_boss', note: 'чекаємо трос');
        $service->changeStatus($complaint, Complaint::STATUS_DONE, 'luda_boss');

        $this->assertNull($complaint->getResolution());

        $service->changeStatus($complaint, Complaint::STATUS_ON_HOLD, 'luda_boss', note: 'знову чекаємо');
        $service->changeStatus($complaint, Complaint::STATUS_DONE, 'luda_boss', note: 'замінили трос');

        $this->assertSame('замінили трос', $complaint->getResolution());
    }

    public function testOnHoldIsAKnownStatus(): void
    {
        $this->assertContains(Complaint::STATUS_ON_HOLD, Complaint::STATUSES);
        $this->assertSame('⏸ Відкладено', $this->service()->statusLabel(Complaint::STATUS_ON_HOLD));
    }

    #############
    # 💬 The official discussion
    #############

    /**
     * Read by the whole house, written by two people.
     *
     * Opening it to every linked resident turns the thread under the broken lift into the
     * chat this register was built to replace, and buries the one answer that matters. A
     * neighbour with the same problem files their own entry — that is what the list is for.
     */
    public function testOnlyTheAuthorAndTheHeadOfTheOsbbMayComment(): void
    {
        $service = $this->service('267957704');

        $mine = $this->account('85', id: 85);
        $neighbour = $this->account('86', id: 86);

        $complaint = (new Complaint())->setAccount($mine)->setText('Не працює ліфт');

        $author = $this->user('111222333');
        $luda = $this->user('267957704');
        $someoneElse = $this->user('444555666');

        $this->assertTrue($service->mayComment($complaint, $author, $mine));
        $this->assertTrue($service->mayComment($complaint, $luda, null));
        $this->assertFalse($service->mayComment($complaint, $someoneElse, $neighbour));
        $this->assertFalse($service->mayComment($complaint, null, null));
    }

    /**
     * A family member writing from their own Telegram is the author of that flat's entry:
     * the complaint belongs to the Account, not to the person who happened to type it.
     */
    public function testAnyFamilyMemberOfTheFlatMayComment(): void
    {
        $account = $this->account('85', id: 85);
        $complaint = (new Complaint())->setAccount($account)->setText('Не працює ліфт');

        $this->assertTrue($this->service()->mayComment($complaint, $this->user('999888777'), $account));
    }

    #############
    # Who filed it — shown to the head of the ОСББ, and to nobody else
    #############

    public function testAuthorChatUrlPrefersTheUsernameAndFallsBackToThePhone(): void
    {
        $service = $this->service();

        $withUsername = $this->contactUser('ivan_shuba', '380671234567');
        $phoneOnly = $this->contactUser(null, '+380 67 470 46 24');
        $neither = $this->contactUser(null, null);

        $complaint = fn (TelegramUser $u): Complaint => (new Complaint())->setAuthor($u);

        $this->assertSame('https://t.me/ivan_shuba', $service->authorChatUrl($complaint($withUsername)));
        // Only ~48% of telegram_user rows carry a username, so this is the common branch —
        // the same t.me/+<phone> shape the main menu already uses for Людмила's number.
        $this->assertSame('https://t.me/+380674704624', $service->authorChatUrl($complaint($phoneOnly)));
        $this->assertNull($service->authorChatUrl($complaint($neither)));
        $this->assertNull($service->authorChatUrl(new Complaint()));
    }

    public function testAuthorContactLineSurvivesAMissingAuthor(): void
    {
        $line = $this->service()->authorContactLine(new Complaint());

        $this->assertStringContainsString('Автора вже немає', $line);
    }

    /**
     * The label frozen into a comment is a display value, not an escaped fragment: it is
     * stored, and whatever renders it escapes it again.
     */
    public function testCommentLabelForAResidentNamesTheBuilding(): void
    {
        $complaint = (new Complaint())->setAccount($this->account('85'));

        $this->assertSame('буд. 23, кв. 85', $this->service()->place($complaint));
    }

    /** «luda_boss» must not appear under an official answer the whole house reads. */
    public function testAdminLoginsAreShownAsPeople(): void
    {
        $service = $this->service();

        $this->assertSame('Людмила (голова ОСББ)', $service->adminLabel('luda_boss'));
        $this->assertSame('ОСББ', $service->adminLabel('main_admin'));
        $this->assertSame('ОСББ', $service->adminLabel(null));
    }
}
