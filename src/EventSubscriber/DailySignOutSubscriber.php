<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * A panel session lasts the calendar day it began, and ends at midnight Kyiv.
 *
 * `/admin/logins` is read to answer «хто заходив», and it can only answer that with the
 * sign-ins that actually happened. Nothing here expired: PHP's own session GC never runs
 * against a custom `save_path` on this distribution, so a session file sat on disk
 * indefinitely and somebody who signed in once in July was still signed in in September —
 * four colleagues, one row a month, and a log that could not tell a normal week from a
 * quiet one. Иван asked for the opposite: «не важно кто, в 24:00 всех разлогин».
 *
 * **The day, not 24 hours.** A rolling window would drift — sign in at 10:32 today and you
 * are asked again at 10:32 tomorrow, then at 11:05, and the log stops being readable as a
 * day-by-day list. A calendar day in Kyiv gives one row per person per day they worked,
 * which is exactly the shape the page is read in.
 *
 * The stamp is set at login and checked on every `/admin` request. A session that predates
 * this rule carries no stamp and is given today's rather than being thrown out: shipping
 * the feature must not sign everybody out mid-afternoon, and by tomorrow the rule holds
 * for them anyway.
 */
class DailySignOutSubscriber implements EventSubscriberInterface
{
    public const SESSION_KEY = 'panel.signed_in_on';

    /** The house is in Cherkasy; midnight means midnight there, DST included. */
    public const TIMEZONE = 'Europe/Kyiv';

    public function __construct(
        private UrlGeneratorInterface $urls,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'stamp',
            // Symfony's firewall listens on kernel.request at priority 8. This runs just
            // after it, so the session is the authenticated one by the time it is read.
            KernelEvents::REQUEST => ['expire', 6],
        ];
    }

    public function stamp(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();

        if (!$request->hasSession()) {
            return;
        }

        $request->getSession()->set(self::SESSION_KEY, self::today());
    }

    public function expire(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Only the panel. /login must stay reachable or an expired session would bounce
        // between the two forever, and the bot's own /hook has no session at all.
        if (!str_starts_with($request->getPathInfo(), '/admin')) {
            return;
        }

        if (!$request->hasSession() || !$request->hasPreviousSession()) {
            return;
        }

        $session = $request->getSession();
        $stamped = $session->get(self::SESSION_KEY);
        $today = self::today();

        if ($stamped === null) {
            $session->set(self::SESSION_KEY, $today);

            return;
        }

        if ($stamped === $today) {
            return;
        }

        $session->invalidate();

        $event->setResponse(new RedirectResponse(
            $this->urls->generate('app_login') . '?expired=1'
        ));
    }

    private static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
    }
}
