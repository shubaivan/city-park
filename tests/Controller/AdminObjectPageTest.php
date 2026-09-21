<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\ExpectedResident;
use App\Entity\TelegramUser;
use App\Service\PropertyRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

/**
 * The object card, rendered — and specifically the register of numbers we expect on it.
 *
 * An object with nobody in the bot receives none of the bot's notices, and ~790 of the
 * ЖК's 966 objects are in exactly that state. Writing the owner's number down here is the
 * only thing that changes it without somebody watching for them to arrive, so the form
 * has to actually be on the page — the guard buttons shipped invisible on 07.09.2026 and
 * nothing anywhere said so.
 */
class AdminObjectPageTest extends KernelTestCase
{
    private function render(array $extra = [], string $role = 'ROLE_ADMIN'): string
    {
        self::bootKernel();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(new InMemoryUser('alina', null, [$role]), 'main', [$role]),
        );

        return self::getContainer()->get(Environment::class)->render('admin/object.html.twig', array_merge([
            'account' => $this->account(),
            'registry' => self::getContainer()->get(PropertyRegistry::class),
            'threshold' => 939.60,
            'tariff' => 13.5,
            'block' => null,
            'owners' => [],
            'siblings' => [],
            'groupDebt' => 16314.49,
            'complaints' => [],
            'expected' => [],
            'history' => [],
        ], $extra));
    }

    private function account(int $id = 356, string $number = '220050'): Account
    {
        $account = (new Account())
            ->setAccountNumber($number)
            ->setApartmentNumber('50')
            ->setHouseNumber('19')
            ->setStreet('Козацька')
            ->setDebt('16314.49')
            ->setArea('46.40');
        $account->setIsActive(false);

        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }

    public function testAnObjectWithNobodyInTheBotIsOfferedTheForm(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Очікуємо в боті', $html);
        $this->assertStringContainsString('/admin/objects/expected/add', $html);
        $this->assertStringContainsString('name="phone"', $html);
    }

    public function testAWrittenDownNumberIsShownWaiting(): void
    {
        $expected = new ExpectedResident($this->account(), '+380932729951', 'Іван Доненко', 'alina');

        $html = $this->render(['expected' => [$expected]]);

        $this->assertStringContainsString('Іван Доненко', $html);
        $this->assertStringContainsString('+380932729951', $html);
        $this->assertStringContainsString('чекаємо', $html);
        $this->assertStringContainsString('/admin/objects/expected/remove', $html);
    }

    /**
     * The card has to say that the pre-registration worked.
     *
     * A row that vanished on being used is indistinguishable from one nobody ever typed,
     * and the person who typed it is the one asking whether it did anything.
     */
    public function testAClaimedNumberSaysSoAndCannotBeWithdrawn(): void
    {
        $expected = new ExpectedResident($this->account(), '+380932729951', 'Іван Доненко', 'alina');
        $user = (new TelegramUser())->setTelegramId('471925876');
        $user->setFirstName('Іван');
        (new \ReflectionProperty(TelegramUser::class, 'id'))->setValue($user, 7);
        $expected->claim($user);

        $html = $this->render(['expected' => [$expected]]);

        $this->assertStringContainsString('прийшов', $html);
        $this->assertStringNotContainsString('/admin/objects/expected/remove', $html);
    }

    /**
     * Сергій reads this page and changes nothing on it — a form that renders and then 403s
     * is reported as «панель не працює».
     */
    public function testTheComplaintsRoleSeesNoFormsHere(): void
    {
        $expected = new ExpectedResident($this->account(), '+380932729951', 'Іван Доненко', 'alina');

        $html = $this->render(['expected' => [$expected]], 'ROLE_COMPLAINTS');

        $this->assertStringContainsString('Іван Доненко', $html);
        $this->assertStringNotContainsString('/admin/objects/expected/add', $html);
        $this->assertStringNotContainsString('/admin/objects/expected/remove', $html);
    }
}
