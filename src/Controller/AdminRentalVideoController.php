<?php

namespace App\Controller;

use App\Entity\RentalListing;
use App\Repository\RentalListingRepository;
use App\Service\AdminTelegramChats;
use App\Service\TelegramFileProxy;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Watching a listing's video from /admin/rentals. ROLE_ADMIN, by the catch-all `^/admin`
 * rule in security.yaml.
 *
 * Up to 20 MB plays in the page, through {@see TelegramFileProxy}. Telegram gives a bot no
 * download above that, so a larger one is sent to the admin's own chat, where it plays at
 * any size: resending by `file_id` has no limit.
 */
class AdminRentalVideoController extends AbstractController
{
    #[Route('/admin/rentals/{id}/video', name: 'app_admin_rental_video', requirements: ['id' => '\d+'], methods: [Request::METHOD_GET])]
    public function video(
        int $id,
        Request $request,
        RentalListingRepository $listings,
        TelegramFileProxy $proxy,
    ): Response {
        $listing = $listings->find($id);

        if (!$listing instanceof RentalListing || !$listing->fitsAdminPlayer()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->releaseSession($request);

        $mime = (string)$listing->getVideoMime();

        return $proxy->stream(
            (string)$listing->getVideoFileId(),
            str_starts_with($mime, 'video/') ? $mime : 'video/mp4',
            $request->headers->get('Range'),
        );
    }

    #[Route('/admin/rentals/{id}/video/thumb', name: 'app_admin_rental_video_thumb', requirements: ['id' => '\d+'], methods: [Request::METHOD_GET])]
    public function thumb(
        int $id,
        Request $request,
        RentalListingRepository $listings,
        TelegramFileProxy $proxy,
    ): Response {
        $thumb = $listings->find($id)?->getVideoThumbFileId();

        if ($thumb === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->releaseSession($request);

        return $proxy->stream($thumb, 'image/jpeg');
    }

    #[Route('/admin/rentals/{id}/video/send', name: 'app_admin_rental_video_send', requirements: ['id' => '\d+'], methods: [Request::METHOD_POST])]
    public function send(
        int $id,
        RentalListingRepository $listings,
        AdminTelegramChats $chats,
        Nutgram $bot,
        LoggerInterface $logger,
    ): Response {
        $listing = $listings->find($id);
        $back = $this->redirectToRoute('app_admin_rentals', ['_fragment' => 'listing-' . $id]);

        if (!$listing instanceof RentalListing || !$listing->hasVideo()) {
            $this->addFlash('error', 'У цього оголошення немає відео.');

            return $back;
        }

        $chat = $chats->chatFor((string)$this->getUser()?->getUserIdentifier());

        if ($chat === null) {
            $this->addFlash('error', 'Ваш логін не прив\'язаний до Telegram, тому бот не знає, куди надіслати відео. Попросіть Івана додати ваш чат.');

            return $back;
        }

        try {
            $bot->sendVideo(
                video: (string)$listing->getVideoFileId(),
                chat_id: $chat,
                caption: sprintf('🎬 Відео оголошення #%d (панель адміністратора)', $id),
                supports_streaming: true,
            );
        } catch (\Throwable $e) {
            $logger->error('rental video to admin failed', ['listing_id' => $id, 'exception' => $e::class]);
            $this->addFlash('error', 'Бот не зміг надіслати відео. Перевірте, що ви відкрили бот і натиснули «Старт», і спробуйте ще раз.');

            return $back;
        }

        $this->addFlash('success', 'Відео надіслано вам у бот.');

        return $back;
    }

    /** A stream can run for minutes; holding the session lock would freeze the admin's other tabs. */
    private function releaseSession(Request $request): void
    {
        if ($request->hasSession()) {
            $request->getSession()->save();
        }
    }
}
