<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\DailySignOutSubscriber;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The panel signs everybody out at midnight Kyiv, and `/admin/logins` is why.
 *
 * That page answers «хто заходив», and it can only answer with sign-ins that happened.
 * PHP's session GC never runs against this project's custom `save_path`, so a session
 * survived indefinitely and four colleagues produced a handful of rows a year.
 */
class DailySignOutTest extends WebTestCase
{
    private function signedIn(string $login = 'main_admin'): KernelBrowser
    {
        $client = static::createClient();
        $provider = static::getContainer()->get('security.user.provider.concrete.users_in_memory');

        $client->loginUser($provider->loadUserByIdentifier($login));

        return $client;
    }

    private function stamp(KernelBrowser $client, string $day): void
    {
        $session = $client->getRequest()->getSession();
        $session->set(DailySignOutSubscriber::SESSION_KEY, $day);
        $session->save();
    }

    public function testASessionFromYesterdayIsSentBackToTheLoginForm(): void
    {
        $client = $this->signedIn();
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $this->stamp($client, '2020-01-01');

        $client->request('GET', '/admin');

        self::assertResponseRedirects();
        self::assertStringContainsString(
            '/login',
            (string)$client->getResponse()->headers->get('Location'),
        );
        self::assertStringContainsString(
            'expired=1',
            (string)$client->getResponse()->headers->get('Location'),
            'the form has to say why it is asking again, or this reads as the panel breaking overnight',
        );
    }

    /** Today's session is not touched — the rule is the calendar day, not a rolling window. */
    public function testTodaysSessionIsLeftAlone(): void
    {
        $client = $this->signedIn();
        $client->request('GET', '/admin');

        $today = (new \DateTimeImmutable('now', new \DateTimeZone(DailySignOutSubscriber::TIMEZONE)))
            ->format('Y-m-d');

        $this->stamp($client, $today);

        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
    }

    /**
     * Shipping the rule must not sign everybody out mid-afternoon.
     *
     * Sessions that predate it carry no stamp; they are given today's and expire tomorrow
     * with everybody else's.
     */
    public function testASessionThatPredatesTheRuleSurvivesTheDeploy(): void
    {
        $client = $this->signedIn();
        $client->request('GET', '/admin');

        $session = $client->getRequest()->getSession();
        $session->remove(DailySignOutSubscriber::SESSION_KEY);
        $session->save();

        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
    }

    /** The login form itself must stay reachable, or an expired session bounces forever. */
    public function testTheLoginFormIsNeverExpired(): void
    {
        $client = $this->signedIn();
        $client->request('GET', '/admin');
        $this->stamp($client, '2020-01-01');

        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
    }
}
