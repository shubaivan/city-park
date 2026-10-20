<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\CarPlate;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

/**
 * The house's car list, rendered — for both roles that can open it.
 *
 * The removal button is the accountant's alone: a form that renders for Сергій and then
 * answers 403 reads as «панель не працює», the rule every page of this panel follows.
 */
class AdminCarsPageTest extends KernelTestCase
{
    private function render(string $role): string
    {
        self::bootKernel();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(new InMemoryUser('someone', null, [$role]), 'main', [$role]),
        );

        $account = (new Account())
            ->setAccountNumber('220085')
            ->setApartmentNumber('85')
            ->setHouseNumber('19')
            ->setStreet('Козацька');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, 12);

        $plate = (new CarPlate())->setAccount($account)->setPlate('CA1234AB');
        (new \ReflectionProperty(CarPlate::class, 'id'))->setValue($plate, 3);

        return self::getContainer()->get(Environment::class)->render('admin/cars.html.twig', [
            'plates' => [$plate],
            'objectCount' => 1,
            'totalObjects' => 966,
        ]);
    }

    public function testItListsThePlateWithItsFlatAndTheExport(): void
    {
        $html = $this->render('ROLE_ADMIN');

        $this->assertStringContainsString('CA 1234 AB', $html);
        $this->assertStringContainsString('буд. 19, кв. 85', $html);
        $this->assertStringContainsString('/admin/objects/12', $html);
        $this->assertStringContainsString('/admin/cars.csv', $html);
        $this->assertStringContainsString('з 966', $html);
        $this->assertStringContainsString('/admin/cars/remove', $html);
    }

    public function testTheComplaintsRoleSeesNoRemovalForm(): void
    {
        $html = $this->render('ROLE_COMPLAINTS');

        $this->assertStringContainsString('CA 1234 AB', $html);
        $this->assertStringNotContainsString('/admin/cars/remove', $html);
    }
}
