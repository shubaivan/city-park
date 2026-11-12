<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils, Request $request): Response
    {
        $error = $authenticationUtils->getLastAuthenticationError();

        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            // Sent here by DailySignOutSubscriber. Without a word, being asked for the
            // password again reads as the panel having broken overnight.
            'expired' => $request->query->getBoolean('expired'),
            // The guard's one-time link from the bot was used, expired or forged.
            'link_invalid' => $request->query->get('link') === 'invalid',
        ]);
    }

    /**
     * The bot's one-time link for the gate. Never reached: TelegramLinkAuthenticator
     * answers it — into the scan log, or back to /login with ?link=invalid.
     */
    #[Route(path: '/panel/auth/{token}', name: 'app_panel_auth', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function panelAuth(): Response
    {
        return $this->redirectToRoute('app_login', ['link' => 'invalid']);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
