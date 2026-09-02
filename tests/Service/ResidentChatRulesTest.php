<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Service\ResidentChatService;
use App\Service\TelegramUserService;
use PHPUnit\Framework\TestCase;
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
}
