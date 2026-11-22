<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Repository\ScheduledSetRepository;
use App\Service\GuardService;
use App\Service\TelegramUserService;
use App\Telegram\Guard\Command\GuardQrCommand;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * «🪪 Мій QR-код» hands out the personal code from 22.11.2026 — and only to somebody who
 * really is linked to the flat, since the scan checks exactly that.
 */
class GuardQrPersonalTest extends KernelTestCase
{
    public function testTheCodeNamesThePerson(): void
    {
        $this->assertSame([7, 42], $this->mintedFor(linked: true));
    }

    public function testSomebodyResolvedToAFlatTheyAreNotLinkedToGetsTheFlatCode(): void
    {
        $this->assertSame([7, null], $this->mintedFor(linked: false));
    }

    /** @return array{0:int, 1:?int} the account and person mintToken() was asked for */
    private function mintedFor(bool $linked): array
    {
        self::bootKernel();

        $flat = (new Account())->setAccountNumber('220001')->setApartmentNumber('1')->setHouseNumber('17');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($flat, 7);
        $user = (new TelegramUser())->setTelegramId('5')->setAccount($linked ? $flat : null);
        (new \ReflectionProperty(TelegramUser::class, 'id'))->setValue($user, 42);

        $guard = new class ($this->createMock(ScheduledSetRepository::class), '', 's') extends GuardService {
            /** @var array{0:int, 1:?int}|null */
            public ?array $minted = null;

            public function runningSessionFor(Account $account, \DateTimeInterface $now): ?array
            {
                return null;
            }

            public function mintToken(Account $account, ?TelegramUser $person = null): string
            {
                $this->minted = [(int)$account->getId(), $person?->getId()];

                return parent::mintToken($account, $person);
            }
        };

        $users = $this->createMock(TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn($user);
        $users->method('resolveAccount')->willReturn($flat);

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate(self::getContainer());
        $bot->willReceivePartial(['id' => 1, 'is_bot' => true, 'first_name' => 'bot', 'username' => 'test_bot']);

        (new GuardQrCommand($guard, $users))($bot);

        $this->assertNotNull($guard->minted, 'a code was minted');

        return $guard->minted;
    }
}
