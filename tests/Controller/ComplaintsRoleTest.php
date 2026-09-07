<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * What the complaints role may reach, and what it must not.
 *
 * Сергій answers for repairs. He needs the register, and he needs to see which flat
 * reported what — so people and objects are readable. Everything that *changes* a
 * resident is the accountant's: linking somebody to a flat, moving them between flats,
 * uploading the debt file. Those are the actions that decide who gets into the house chat
 * and whose booking is blocked, and they belong to one pair of hands.
 */
class ComplaintsRoleTest extends WebTestCase
{
    private function loginAs(string $role): KernelBrowser
    {
        $client = static::createClient();
        $client->loginUser(new InMemoryUser('serhii', null, [$role]));

        return $client;
    }

    /** @return array<string, array{string}> */
    public static function readable(): array
    {
        return [
            'the register itself' => ['/admin/complaints'],
            'the dashboard that leads to it' => ['/admin'],
            'people, to see who reported what' => ['/admin/users'],
            'objects, for the same reason' => ['/admin/objects'],
        ];
    }

    /** @dataProvider readable */
    public function testTheComplaintsRoleCanLookAtTheseAdminPages(string $path): void
    {
        $client = $this->loginAs('ROLE_COMPLAINTS');
        $client->request('GET', $path);

        $this->assertNotSame(
            403,
            $client->getResponse()->getStatusCode(),
            $path . ' must stay readable for the complaints role',
        );
    }

    /** @return array<string, array{string, string}> */
    public static function forbidden(): array
    {
        return [
            'the debt upload' => ['GET', '/admin/debt'],
            'the area registry' => ['GET', '/admin/area'],
            'the tariff' => ['GET', '/admin/tariff'],
            'community blocking votes' => ['GET', '/admin/block-votes'],
            'linking a resident to a flat' => ['POST', '/admin/users/1/move'],
            'changing somebody\'s role' => ['POST', '/admin/users/1/role'],
            'blocking a resident' => ['POST', '/admin/users/1/status'],
            'creating an object' => ['POST', '/admin/objects/create'],
        ];
    }

    /** @dataProvider forbidden */
    public function testTheComplaintsRoleIsRefusedEverythingThatChangesAResident(string $method, string $path): void
    {
        $client = $this->loginAs('ROLE_COMPLAINTS');
        $client->request($method, $path);

        // Symfony answers a denied *authenticated* request with 403, and a firewall with a
        // form login may instead bounce to /login. Either is a refusal; what must never
        // happen is 200, which would mean the page opened.
        $status = $client->getResponse()->getStatusCode();

        $this->assertContains(
            $status,
            [302, 403],
            sprintf('%s %s must be refused to the complaints role, got %d', $method, $path, $status),
        );

        if ($status === 302) {
            $this->assertStringContainsString(
                '/login',
                (string)$client->getResponse()->headers->get('Location'),
                'a refusal may redirect only to the login page',
            );
        }
    }

    /** The full administrator keeps everything, or this split has quietly broken the panel. */
    public function testTheAdministratorStillReachesTheDebtUpload(): void
    {
        $client = $this->loginAs('ROLE_ADMIN');
        $client->request('GET', '/admin/debt');

        $this->assertNotSame(403, $client->getResponse()->getStatusCode());
    }
}
