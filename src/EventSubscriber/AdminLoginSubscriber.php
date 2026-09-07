<?php

namespace App\EventSubscriber;

use App\Entity\AdminLogin;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Writes one row per sign-in attempt on the panel — see App\Entity\AdminLogin for why.
 *
 * Both halves are recorded from Symfony's own security events rather than from the login
 * controller: the controller is not where authentication succeeds, and a check hung off
 * it would miss anything that ever authenticates by another route.
 *
 * **Never fatal.** A login that fails to be logged must still be a login: the panel is
 * how the accountant works, and a broken write here would lock everybody out over a
 * bookkeeping detail. The failure goes to the log and the request carries on.
 */
class AdminLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private RequestStack $requests,
        private LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onSuccess',
            LoginFailureEvent::class => 'onFailure',
        ];
    }

    public function onSuccess(LoginSuccessEvent $event): void
    {
        $this->record($event->getUser()->getUserIdentifier(), true);
    }

    public function onFailure(LoginFailureEvent $event): void
    {
        // The name that was typed, which on a failure is usually the whole story — and
        // the only thing taken from the form. The password is never read here.
        $typed = (string)($event->getRequest()->request->get('_username') ?? '');

        $this->record($typed !== '' ? $typed : '—', false);
    }

    private function record(string $login, bool $success): void
    {
        try {
            $request = $this->requests->getCurrentRequest();

            $entry = (new AdminLogin())
                ->setLogin($login)
                ->setSuccess($success)
                ->setIp($request?->getClientIp())
                ->setAgent((string)$request?->headers->get('User-Agent'));

            $this->em->persist($entry);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('admin login not recorded', [
                'login' => $login,
                'success' => $success,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
