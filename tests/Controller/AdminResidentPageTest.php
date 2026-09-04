<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Service\PropertyRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

/**
 * The resident card, rendered.
 *
 * It replaced a modal — a narrow column over the table with no address, which could be
 * closed with no way back to it. The page is now linked to from the objects register, so
 * it has to survive the shapes the data actually takes: somebody with no особовий рахунок,
 * somebody with no name, an account with nobody else on it.
 */
class AdminResidentPageTest extends KernelTestCase
{
    private function render(TelegramUser $user, ?Account $account, array $extra = []): string
    {
        self::bootKernel();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(new InMemoryUser('alina', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']),
        );

        return self::getContainer()->get(Environment::class)->render('admin/resident.html.twig', array_merge([
            'user' => $user,
            'account' => $account,
            'threshold' => $account ? 1024.65 : null,
            'tariff' => 13.5,
            'block' => null,
            'siblings' => [],
            'registry' => self::getContainer()->get(PropertyRegistry::class),
            'history' => [],
            'roommates' => [],
        ], $extra));
    }

    private function user(int $id, ?string $first = 'Іван', ?Account $account = null): TelegramUser
    {
        $user = new TelegramUser();
        $user->setFirstName($first);
        $user->setLastName($first === null ? null : 'Шуба');
        $user->setUsername(null);
        $user->setPhoneNumber('380633022666');
        $user->setTelegramId('471925876');
        $user->setChatId('471925876');
        $user->setRole('owner');
        $user->setAccount($account);

        (new \ReflectionProperty(TelegramUser::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function account(int $id = 2, string $number = '230085'): Account
    {
        $account = (new Account())
            ->setAccountNumber($number)
            ->setApartmentNumber('85')
            ->setHouseNumber('19')
            ->setStreet('Козацька')
            ->setDebt('3416.00')
            ->setArea('50.60');
        $account->setIsActive(true);

        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }

    public function testItShowsTheResidentTheirFlatAndTheirActions(): void
    {
        $account = $this->account();
        $html = $this->render($this->user(1, account: $account), $account);

        $this->assertStringContainsString('Іван Шуба', $html);
        $this->assertStringContainsString('href="tel:+380633022666"', $html);
        $this->assertStringContainsString('буд. 19, кв. 85', $html);
        $this->assertStringContainsString('230085', $html);
        $this->assertStringContainsString('1 024.65', $html);
        // Every action is its own form, so none of them can happen as a side effect of another.
        foreach (['/role', '/account', '/status', '/move', '/phones', '/group/link'] as $endpoint) {
            $this->assertStringContainsString('/admin/users/1' . $endpoint, $html);
        }
    }

    /**
     * 268 rows on prod have no особовий рахунок — anyone who has ever pressed /start. The
     * card must open for them and say what is missing, not blow up.
     */
    public function testItOpensForSomebodyWithNoAccount(): void
    {
        $html = $this->render($this->user(7, 'Оля'), null);

        $this->assertStringContainsString("Не прив'язаний до рахунку", $html);
        $this->assertStringContainsString('Немає рахунку', $html);
        $this->assertStringNotContainsString('/admin/users/7/status', $html, 'no status form without an account');
    }

    public function testItOpensForSomebodyWithNoName(): void
    {
        $html = $this->render($this->user(9, null), null);

        $this->assertStringContainsString('без імені', $html);
    }

    /** A blocked account shows why, and offers the opposite action. */
    public function testABlockedAccountOffersUnblocking(): void
    {
        $account = $this->account();
        $account->setIsActive(false);

        $html = $this->render($this->user(1, account: $account), $account, [
            'block' => ['code' => 'debt', 'label' => '💰 Борг понад поріг', 'details' => '3416.00 грн (поріг 1024.65 грн)'],
        ]);

        $this->assertStringContainsString('💰 Борг понад поріг', $html);
        $this->assertStringContainsString('Розблокувати', $html);
        $this->assertStringNotContainsString('⛔ Заблокувати', $html);
    }
}
