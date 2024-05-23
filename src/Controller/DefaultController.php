<?php

namespace App\Controller;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DefaultController extends AbstractController
{
    #[Route('/', name: 'app_mail')]
    public function index(Nutgram $bot): Response
    {
//        /** @var Message $message */
//        $message = $bot->sendMessage(
//            text: 'Реклама! <b>Масло для двигателя 100грн</b>. З Вами звяжется наш менеджер',
//            chat_id: 341643951,
//            parse_mode: ParseMode::HTML
//        );

        return $this->redirectToRoute('app_admin');
    }
}
