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
 * The objects register, rendered.
 *
 * `lint:twig` proves the file parses; it says nothing about a property that does not exist
 * on the entity, and this page is assembled from a view model with a dozen of them.
 */
class AdminObjectsPageTest extends KernelTestCase
{
    private function render(array $rows, array $stats, array $houses = []): string
    {
        self::bootKernel();

        // base.html.twig greets app.user unguarded, and every admin page extends it.
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(new InMemoryUser('alina', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']),
        );

        return self::getContainer()->get(Environment::class)->render(
            'admin/objects.html.twig',
            ['rows' => $rows, 'stats' => $stats, 'houses' => $houses],
        );
    }

    private function account(int $id, string $number, string $unit): Account
    {
        $account = (new Account())
            ->setAccountNumber($number)
            ->setApartmentNumber($unit)
            ->setHouseNumber('19')
            ->setStreet('Козацька')
            ->setDebt('2500.00');

        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }

    private function row(Account $account, array $owners = [], array $siblings = []): array
    {
        return [
            'account' => $account,
            'type' => PropertyRegistry::TYPE_APARTMENT,
            'type_label' => '🏠 Квартира',
            'place' => 'буд. 19, кв. 85',
            'owners' => $owners,
            'debt' => 2500.0,
            'threshold' => 1059.08,
            'over_threshold' => true,
            'block' => null,
            'siblings' => $siblings,
            'group_debt' => 2800.0,
        ];
    }

    private function stats(int $objects = 1): array
    {
        return [
            'objects' => $objects,
            'apartments' => $objects,
            'parking' => 0,
            'storage' => 0,
            'unowned' => 0,
            'grouped' => 0,
            'debt' => 2500.0,
            'in_debt' => 1,
            'multi_owner' => 0,
            'many_owner' => 0,
        ];
    }

    public function testItRendersAnObjectWithItsOwnersAndGroup(): void
    {
        $owner = new TelegramUser();
        (new \ReflectionProperty(TelegramUser::class, 'id'))->setValue($owner, 42);
        $owner->setFirstName('Іван');
        $owner->setLastName('Шуба');
        $owner->setUsername(null);
        $owner->setPhoneNumber('+380 67 123 45 67');
        $owner->setRole('owner');

        $parking = $this->account(138, '2170138', 'Паркінг 138');

        $html = $this->render([$this->row($this->account(85, '4100085', '85'), [$owner], [$parking])], $this->stats());

        $this->assertStringContainsString('буд. 19, кв. 85', $html);
        $this->assertStringContainsString('о/р 4100085', $html);
        $this->assertStringContainsString('Іван Шуба', $html);
        // The name is the way into that person's card.
        $this->assertStringContainsString('/admin/users/42', $html);
        $this->assertStringContainsString('Власник', $html);
        $this->assertStringContainsString('href="tel:+380671234567"', $html);
        // The other object of the same owner, and the combined figure.
        $this->assertStringContainsString('Паркінг 138', $html);
        $this->assertStringContainsString('2 800.00', $html);
    }

    /**
     * The row nobody is chasing: every notice the bot sends goes to a TelegramUser, so an
     * object with none has a debt that reaches no-one. The page has to say that out loud.
     */
    public function testAnObjectWithNoOwnerSaysWhatThatMeans(): void
    {
        $html = $this->render([$this->row($this->account(85, '4100085', '85'))], $this->stats());

        $this->assertStringContainsString('Жоден мешканець не прив', $html);
        $this->assertStringContainsString('нікого не сповіщають', $html);
    }

    public function testTheEmptyRegisterStillRenders(): void
    {
        $html = $this->render([], [
            'objects' => 0, 'apartments' => 0, 'parking' => 0, 'storage' => 0,
            'unowned' => 0, 'grouped' => 0, 'debt' => 0.0, 'in_debt' => 0,
            'multi_owner' => 0, 'many_owner' => 0,
        ]);

        $this->assertStringContainsString("Об'єкти нерухомості", $html);
    }

    /**
     * Creating an object was possible only by moving a person onto a new особовий рахунок,
     * which is the wrong tool for a кладова — it would take its owner off their flat.
     */
    public function testThePageOffersAWayToAddAnObject(): void
    {
        $html = $this->render([], [
            'objects' => 0, 'apartments' => 0, 'parking' => 0, 'storage' => 0,
            'unowned' => 0, 'grouped' => 0, 'debt' => 0.0, 'in_debt' => 0,
            'multi_owner' => 0, 'many_owner' => 0,
        ]);

        $this->assertStringContainsString('Додати обʼєкт', $html);
        $this->assertStringContainsString('name="account_number"', $html);
        $this->assertStringContainsString('name="unit_type"', $html);
        $this->assertStringContainsString('name="area"', $html);
    }
}
