<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Repository\ScheduledSetRepository;
use App\Service\GuardService;
use App\Service\TelegramUserService;
use App\Telegram\Guard\Command\GuardQrCommand;
use PHPUnit\Framework\TestCase;

/**
 * «🪪 Мій QR-код» hands out only the personal code from 22.11.2026 — and only to somebody
 * really linked to the flat, since the scan checks exactly that. No flat-level code is
 * minted any more: it could never be revoked.
 */
class GuardQrPersonalTest extends TestCase
{
    private function command(): GuardQrCommand
    {
        return new GuardQrCommand(
            new GuardService($this->createMock(ScheduledSetRepository::class), '', 's'),
            $this->createMock(TelegramUserService::class),
        );
    }

    private function flat(): Account
    {
        $flat = (new Account())->setAccountNumber('220001')->setApartmentNumber('1')->setHouseNumber('17');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($flat, 7);

        return $flat;
    }

    private function user(?Account $account, int $id = 42): TelegramUser
    {
        $user = (new TelegramUser())->setTelegramId('5')->setAccount($account);
        (new \ReflectionProperty(TelegramUser::class, 'id'))->setValue($user, $id);

        return $user;
    }

    public function testTheCodeNamesThePerson(): void
    {
        $flat = $this->flat();
        $token = $this->command()->tokenFor($this->user($flat), $flat);

        $this->assertMatchesRegularExpression('/^g-7-42-[a-f0-9]{16}$/', (string)$token);
    }

    public function testSomebodyNotLinkedToTheFlatGetsNoCode(): void
    {
        $this->assertNull($this->command()->tokenFor($this->user(null), $this->flat()));
        $other = (new Account())->setAccountNumber('220002');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($other, 8);
        $this->assertNull($this->command()->tokenFor($this->user($other), $this->flat()));
    }
}
