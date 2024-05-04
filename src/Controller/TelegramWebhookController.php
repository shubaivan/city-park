<?php

namespace App\Controller;

use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TelegramWebhookController extends AbstractController
{
    #[Route('/hook', name: 'app_webhook')]
    public function hook(Nutgram $bot): Response
    {
        $bot->run();

        return new Response();
    }
}
