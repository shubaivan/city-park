<?php

namespace App\Controller;

use App\Entity\Complaint;
use App\Service\ComplaintService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The photo page for a complaint, opened from a one-shot link the bot hands the author.
 *
 * Authorisation is the token and nothing else, exactly as on the rental photo page: it is
 * random, expires in a day, is re-issued on every request from the bot, and grants one
 * capability — adding or removing pictures on one complaint that the whole house can
 * already read.
 *
 * **Why photos do not simply go to the bot.** A picture sent to the bot means one thing
 * and one thing only: evidence from the pavilion. `pavilion:photo:check` materialises the
 * obligation on a 20-minute cron, so for up to twenty minutes after a booking ends there
 * is no open request to match against — any rule of the shape "no open obligation ⇒ this
 * must be a complaint photo" would swallow the pavilion photo of whoever sent it
 * immediately, and the cron would then block that resident for evidence already sent.
 * Keeping this channel on the web leaves that invariant intact.
 */
class ComplaintPhotoController extends AbstractController
{
    public function __construct(
        private ComplaintService $complaints,
    ) {}

    #[Route('/complaint/photo/{token}', name: 'complaint_photo_page', methods: ['GET'])]
    public function page(string $token): Response
    {
        $complaint = $this->complaints->findByToken($token);

        if (!$complaint instanceof Complaint) {
            return $this->render('complaint/photo_expired.html.twig', [], new Response('', Response::HTTP_NOT_FOUND));
        }

        return $this->render('complaint/photo_upload.html.twig', [
            'complaint' => $complaint,
            'token' => $token,
            'max' => Complaint::PHOTOS_MAX,
        ]);
    }

    #[Route('/complaint/photo/{token}/upload', name: 'complaint_photo_upload', methods: ['POST'])]
    public function upload(string $token, Request $request): JsonResponse
    {
        $complaint = $this->complaints->findByToken($token);

        if (!$complaint instanceof Complaint) {
            return new JsonResponse(['error' => 'Посилання застаріло.'], Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('photo');

        if (!$file) {
            return new JsonResponse(['error' => 'Файл не надійшов.'], Response::HTTP_BAD_REQUEST);
        }

        $error = null;
        $path = $this->complaints->storePhoto($complaint, $file, $error);

        if ($path === null) {
            return new JsonResponse(['error' => $error ?? 'Не вдалося зберегти.'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'path' => $path,
            'count' => count($complaint->getPhotos()),
            'max' => Complaint::PHOTOS_MAX,
        ]);
    }

    #[Route('/complaint/photo/{token}/delete', name: 'complaint_photo_delete', methods: ['POST'])]
    public function delete(string $token, Request $request): JsonResponse
    {
        $complaint = $this->complaints->findByToken($token);

        if (!$complaint instanceof Complaint) {
            return new JsonResponse(['error' => 'Посилання застаріло.'], Response::HTTP_NOT_FOUND);
        }

        $path = (string)$request->request->get('path');

        if (!in_array($path, $complaint->getPhotos(), true)) {
            return new JsonResponse(['error' => 'Фото не знайдено.'], Response::HTTP_BAD_REQUEST);
        }

        $this->complaints->removePhoto($complaint, $path);

        return new JsonResponse(['count' => count($complaint->getPhotos())]);
    }

    /**
     * "Готово": burn the link and let the page close itself back into Telegram.
     */
    #[Route('/complaint/photo/{token}/done', name: 'complaint_photo_done', methods: ['POST'])]
    public function done(string $token): JsonResponse
    {
        $complaint = $this->complaints->findByToken($token);

        if (!$complaint instanceof Complaint) {
            return new JsonResponse(['error' => 'Посилання застаріло.'], Response::HTTP_NOT_FOUND);
        }

        $this->complaints->burnPhotoToken($complaint);

        return new JsonResponse(['ok' => true]);
    }
}
