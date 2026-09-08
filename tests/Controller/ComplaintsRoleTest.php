<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
    /**
     * Sign in as one of the panel's real logins, taken from the provider.
     *
     * Not `new InMemoryUser('serhii', null, [$role])`, which is the obvious shape and does
     * not work: the session user would carry a null password, the provider refreshes it
     * into the configured one, and Symfony reads the difference as "the user changed",
     * drops the token and bounces to /login. Every request in this class then answered 302
     * — which both halves accepted as a refusal, so the whole test passed while proving
     * nothing. Found 07.09.2026 while adding the sign-in log.
     */
    private function loginAs(string $login): KernelBrowser
    {
        $client = static::createClient();
        $provider = static::getContainer()->get('security.user.provider.concrete.users_in_memory');

        $client->loginUser($provider->loadUserByIdentifier($login));

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
            // Read by everyone who can sign in, on purpose: a log only the owner opens is
            // an audit trail nobody reads.
            'the sign-in log' => ['/admin/logins'],
        ];
    }

    /** @dataProvider readable */
    public function testTheComplaintsRoleCanLookAtTheseAdminPages(string $path): void
    {
        $client = $this->loginAs('serhii');
        $client->request('GET', $path);

        // Not 200: the test environment has no database, so a page that gets past the
        // firewall answers 500 on its first query. What is being pinned here is the
        // firewall's verdict, and 403 is the only status that means "refused".
        $this->assertNotSame(
            403,
            $client->getResponse()->getStatusCode(),
            $path . ' must stay readable for the complaints role',
        );
        $this->assertNotSame(
            302,
            $client->getResponse()->getStatusCode(),
            $path . ': the client is not signed in, so this test would prove nothing',
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
            'unlinking a resident from their flat' => ['POST', '/admin/users/1/account/unlink'],
            'changing somebody\'s role' => ['POST', '/admin/users/1/role'],
            'blocking a resident' => ['POST', '/admin/users/1/status'],
            'creating an object' => ['POST', '/admin/objects/create'],
        ];
    }

    /** @dataProvider forbidden */
    public function testTheComplaintsRoleIsRefusedEverythingThatChangesAResident(string $method, string $path): void
    {
        $client = $this->loginAs('serhii');
        $client->request($method, $path);

        // 403 and nothing else. A redirect to /login used to be accepted here as "also a
        // refusal", which is true — and it is also what an unauthenticated client gets for
        // every page in the panel, so it made the whole class pass without signing anybody
        // in. An authenticated user who is denied gets 403.
        $this->assertSame(
            403,
            $client->getResponse()->getStatusCode(),
            sprintf('%s %s must be refused to the complaints role', $method, $path),
        );
    }

    /** The full administrator keeps everything, or this split has quietly broken the panel. */
    public function testTheAdministratorStillReachesTheDebtUpload(): void
    {
        $client = $this->loginAs('alina');
        $client->request('GET', '/admin/debt');

        $this->assertNotSame(403, $client->getResponse()->getStatusCode());
    }
}
