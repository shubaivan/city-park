<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Service\ResidentChatService;
use App\Service\TelegramUserService;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatMemberStatus;
use SergiX44\Nutgram\Telegram\Types\Chat\ChatMember;
use SergiX44\Nutgram\Telegram\Types\Chat\ChatMemberAdministrator;
use SergiX44\Nutgram\Telegram\Types\Chat\ChatMemberBanned;
use SergiX44\Nutgram\Telegram\Types\Chat\ChatMemberLeft;
use SergiX44\Nutgram\Telegram\Types\Chat\ChatMemberMember;
use SergiX44\Nutgram\Telegram\Types\Chat\ChatMemberOwner;
use SergiX44\Nutgram\Telegram\Types\Chat\ChatMemberRestricted;
use Psr\Log\NullLogger;

/**
 * Who gets into the residents' chat.
 *
 * The load-bearing rule is the second test: a debt or a missed pavilion photo flips
 * Account::is_active and blocks *booking*, and it must not close the house chat. The
 * chat is where the ОСББ announces things — including, sooner or later, that the person
 * owes money — so locking a debtor out is both petty and self-defeating. This is the
 * same call already made for the rental noticeboard, and the same one somebody will be
 * tempted to "tidy away" later for consistency with the booking rules.
 */
class ResidentChatRulesTest extends TestCase
{
    private function service(): ResidentChatService
    {
        // resolveAccount() short-circuits on a user that already has an account, so the
        // repository is never reached in these cases.
        $users = $this->createMock(TelegramUserRepository::class);
        $userService = $this->createMock(TelegramUserService::class);
        $userService->method('resolveAccount')
            ->willReturnCallback(fn(TelegramUser $u) => $u->getAccount());

        return new ResidentChatService(
            $users,
            $userService,
            new NullLogger(),
            '-1002345678901',
            'https://t.me/+abc123',
        );
    }

    private function user(?Account $account): TelegramUser
    {
        $user = (new TelegramUser())->setTelegramId('471925876');

        if ($account) {
            $user->setAccount($account);
        }

        return $user;
    }

    private function account(string $apartment, bool $isActive = true): Account
    {
        $account = (new Account())
            ->setAccountNumber('1-1-0-085')
            ->setApartmentNumber($apartment)
            ->setHouseNumber('1')
            ->setStreet('Героїв Дніпра');
        $account->setIsActive($isActive);

        return $account;
    }

    public function testLinkedResidentMayJoin(): void
    {
        $this->assertTrue($this->service()->mayJoin($this->user($this->account('85'))));
    }

    public function testBlockedResidentMayStillJoin(): void
    {
        $this->assertTrue(
            $this->service()->mayJoin($this->user($this->account('85', isActive: false))),
            'a debt or missed photo blocks booking, not access to the house announcements',
        );
    }

    public function testParkingOnlyAccountMayJoin(): void
    {
        $this->assertTrue(
            $this->service()->mayJoin($this->user($this->account('паркінг 12'))),
            'parking owners pay ОСББ dues; isNonResidential() only bars booking the pavilion',
        );
    }

    public function testUnlinkedUserMayNotJoin(): void
    {
        $this->assertFalse($this->service()->mayJoin($this->user(null)));
    }

    public function testGateIsInertUntilTheChatExists(): void
    {
        $unconfigured = new ResidentChatService(
            $this->createMock(TelegramUserRepository::class),
            $this->createMock(TelegramUserService::class),
            new NullLogger(),
        );

        $this->assertFalse($unconfigured->isConfigured());
    }

    private function memberUser(): TelegramUser
    {
        $user = new TelegramUser();
        $user->setTelegramId('123456');
        $user->setAccount(new Account());

        return $user;
    }

    private function botReporting(?string $status, bool $isMember = false): Nutgram
    {
        $bot = $this->createMock(Nutgram::class);

        if ($status === null) {
            $bot->method('getChatMember')->willThrowException(new \RuntimeException('Bad Request'));

            return $bot;
        }

        // Telegram returns a different concrete class per status; each already carries
        // its own `status`, so building the real one is closer to production than a stub.
        $member = match ($status) {
            'creator' => new ChatMemberOwner(),
            'administrator' => new ChatMemberAdministrator(),
            'member' => new ChatMemberMember(),
            'left' => new ChatMemberLeft(),
            'kicked' => new ChatMemberBanned(),
            'restricted' => (function () use ($isMember): ChatMember {
                $restricted = new ChatMemberRestricted();
                $restricted->is_member = $isMember;

                return $restricted;
            })(),
        };

        $bot->method('getChatMember')->willReturn($member);

        return $bot;
    }

    /**
     * Somebody already inside must not be offered a door they are standing behind.
     */
    public function testMembersAreRecognised(): void
    {
        foreach (['creator', 'administrator', 'member'] as $status) {
            $this->assertTrue(
                $this->service()->isMember($this->botReporting($status), $this->memberUser()),
                $status . ' should count as being in the chat',
            );
        }
    }

    public function testLeftAndKickedAreNotMembers(): void
    {
        foreach (['left', 'kicked'] as $status) {
            $this->assertFalse(
                $this->service()->isMember($this->botReporting($status), $this->memberUser()),
                $status . ' should not count as being in the chat',
            );
        }
    }

    /**
     * A restricted user who has left still comes back with status "restricted" — only
     * is_member separates the two.
     */
    public function testRestrictedCountsOnlyWhileStillInside(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isMember($this->botReporting('restricted', isMember: true), $this->memberUser()));
        $this->assertFalse($service->isMember($this->botReporting('restricted', isMember: false), $this->memberUser()));
    }

    /**
     * Telegram unreachable must not hide the door: null makes the caller fall back to the
     * ordinary invitation, because showing it to a member is the smaller mistake.
     */
    public function testUnknownWhenTelegramCannotBeAsked(): void
    {
        $this->assertNull($this->service()->isMember($this->botReporting(null), $this->memberUser()));
    }
}
