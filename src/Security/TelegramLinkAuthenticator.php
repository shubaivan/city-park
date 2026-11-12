<?php

namespace App\Security;

use App\Service\PanelLoginLink;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * `/panel/auth/{token}` — the bot's one-time link. A good link signs the session in as
 * the gate (ROLE_GUARD) and lands on the QR scan log; anything else goes to /login with
 * a line saying to ask the bot for a new one.
 */
class TelegramLinkAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private PanelLoginLink $links,
        private UrlGeneratorInterface $urls,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'app_panel_auth' && $request->isMethod('GET');
    }

    public function authenticate(Request $request): Passport
    {
        $telegramId = $this->links->consume((string)$request->attributes->get('token'));

        if ($telegramId === null) {
            throw new CustomUserMessageAuthenticationException('Посилання недійсне або прострочене.');
        }

        return new SelfValidatingPassport(new UserBadge(
            GuardUser::IDENTIFIER,
            static fn () => new GuardUser($telegramId),
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->urls->generate('app_admin_scans'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new RedirectResponse($this->urls->generate('app_login', ['link' => 'invalid']));
    }
}
