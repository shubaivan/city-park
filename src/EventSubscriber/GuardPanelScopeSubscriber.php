<?php

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * The gate's own panel login (ROLE_GUARD): the QR scan log and nothing else.
 *
 * The post's phone is on a desk in the гардпост and anybody standing there can pick it
 * up, while the rest of the panel holds every resident's phone, debt and flat. So the
 * guard's session is confined twice: `access_control` refuses every other /admin path
 * (it lists ROLE_GUARD on `/admin/scans` only), and this subscriber is an allowlist on
 * top of it — a line added to `access_control` later that happens to admit ROLE_GUARD
 * still does not open a page here.
 *
 * - After signing in the guard lands on the scan log, not on the dashboard and not on
 *   whatever page the session was bounced from.
 * - `/admin` itself (the brand link, an old bookmark) is sent to the scan log rather than
 *   answered with a 403 — he has one page, and that is where home is.
 * - Every other /admin route is 403, GET or POST, whatever `access_control` said.
 *
 * Somebody who holds ROLE_ADMIN or ROLE_COMPLAINTS as well is not a guard login and is
 * left alone; no real login carries both.
 */
class GuardPanelScopeSubscriber implements EventSubscriberInterface
{
    public const ROLE = 'ROLE_GUARD';

    /** The one page, plus the way out. `/admin` itself is redirected, not served. */
    public const ALLOWED_ROUTES = ['app_admin_scans', 'app_logout'];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'landOnScans',
            // After the firewall (8) — the token must be there — and after the daily
            // sign-out (6), so an expired guard is sent to /login like everybody else.
            KernelEvents::REQUEST => ['confine', 5],
        ];
    }

    public static function isGuardOnly(array $roles): bool
    {
        return in_array(self::ROLE, $roles, true)
            && !in_array('ROLE_ADMIN', $roles, true)
            && !in_array('ROLE_COMPLAINTS', $roles, true);
    }

    public function landOnScans(LoginSuccessEvent $event): void
    {
        if (!self::isGuardOnly($event->getAuthenticatedToken()->getRoleNames())) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('app_admin_scans')));
    }

    public function confine(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/admin')) {
            return;
        }

        $token = $this->security->getToken();

        if ($token === null || !self::isGuardOnly($token->getRoleNames())) {
            return;
        }

        $route = (string)$request->attributes->get('_route');

        if ($route === 'app_admin') {
            $event->setResponse(new RedirectResponse($this->urls->generate('app_admin_scans')));

            return;
        }

        if (in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        throw new AccessDeniedException('The guard login reads the QR scan log only.');
    }
}
