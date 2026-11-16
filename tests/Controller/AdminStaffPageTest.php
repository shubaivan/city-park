<?php

namespace App\Tests\Controller;

use App\Entity\ServiceStaff;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

/** «🧹 Клінінг» in the panel: the staff numbers, linked or not, with the add form. */
class AdminStaffPageTest extends KernelTestCase
{
    public function testItListsTheNumbersAndTheForm(): void
    {
        self::bootKernel();
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(new InMemoryUser('admin', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']),
        );

        $linked = (new ServiceStaff('+380 67 000 00 01'))->link('777000111');
        (new \ReflectionProperty(ServiceStaff::class, 'id'))->setValue($linked, 1);
        $off = (new ServiceStaff('0501112233'))->setActive(false);
        (new \ReflectionProperty(ServiceStaff::class, 'id'))->setValue($off, 2);

        $html = self::getContainer()->get(Environment::class)->render('admin/staff.html.twig', ['rows' => [$linked, $off]]);

        $this->assertStringContainsString('+380 67 000 00 01', $html);
        $this->assertStringContainsString('/admin/staff/add', $html);
        $this->assertStringContainsString('/admin/staff/toggle', $html);
        $this->assertStringContainsString('ще не відкривав', $html);
        $this->assertStringContainsString('вимкнено', $html);
    }
}
