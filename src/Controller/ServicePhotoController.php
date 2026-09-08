<?php

namespace App\Controller;

use App\Entity\ServiceOffer;
use App\Service\ServiceOfferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The work-photo upload page for a service offer, opened from a one-shot link the bot
 * hands the author.
 *
 * Authorisation is the token and nothing else — there is no login here and residents have
 * no accounts on the site. That is acceptable because the token is random, short-lived
 * (ServiceOffer::PHOTO_TOKEN_TTL_HOURS), re-issued on every request from the bot, and
 * grants exactly one capability: adding or removing photos on one offer that is already
 * visible to every confirmed resident. It exposes nothing a reader of the offer cannot see.
 *
 * Why photos do not simply go to the bot: see ServiceOffer::$photo_token.
 */
class ServicePhotoController extends AbstractController
{
    public function __construct(
        private ServiceOfferService $offerService,
    ) {}

    #[Route('/service/photo/{token}', name: 'service_photo_page', methods: ['GET'])]
    public function page(string $token): Response
    {
        $offer = $this->offerService->findByToken($token);

        if (!$offer) {
            return $this->render('service/photo_expired.html.twig', [], new Response('', Response::HTTP_NOT_FOUND));
        }

        return $this->render('service/photo_upload.html.twig', [
            'offer' => $offer,
            'token' => $token,
            'max' => ServiceOffer::PHOTOS_MAX,
        ]);
    }

    #[Route('/service/photo/{token}/upload', name: 'service_photo_upload', methods: ['POST'])]
    public function upload(string $token, Request $request): JsonResponse
    {
        $offer = $this->offerService->findByToken($token);

        if (!$offer) {
            return new JsonResponse(['error' => 'Посилання застаріло.'], Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('photo');

        if (!$file) {
            return new JsonResponse(['error' => 'Файл не надійшов.'], Response::HTTP_BAD_REQUEST);
        }

        $error = null;
        $path = $this->offerService->storePhoto($offer, $file, $error);

        if (!$path) {
            return new JsonResponse(['error' => $error ?? 'Не вдалося зберегти.'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'path' => $path,
            'count' => count($offer->getPhotos()),
            'max' => ServiceOffer::PHOTOS_MAX,
        ]);
    }

    /**
     * "Готово" on the page: push the updated card to the author's chat, then the page
     * closes itself via Telegram.WebApp.close() and they land back on it.
     *
     * The token is burned here — the link has done its job, and one fewer live link is one
     * fewer thing to forward by accident.
     */
    #[Route('/service/photo/{token}/done', name: 'service_photo_done', methods: ['POST'])]
    public function done(string $token): JsonResponse
    {
        $offer = $this->offerService->findByToken($token);

        if (!$offer) {
            return new JsonResponse(['error' => 'Посилання застаріло.'], Response::HTTP_NOT_FOUND);
        }

        $this->offerService->notifyPhotosUpdated($offer);
        $this->offerService->burnToken($offer);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/service/photo/{token}/delete', name: 'service_photo_delete', methods: ['POST'])]
    public function delete(string $token, Request $request): JsonResponse
    {
        $offer = $this->offerService->findByToken($token);

        if (!$offer) {
            return new JsonResponse(['error' => 'Посилання застаріло.'], Response::HTTP_NOT_FOUND);
        }

        $path = (string)$request->request->get('path');

        if (!in_array($path, $offer->getPhotos(), true)) {
            return new JsonResponse(['error' => 'Фото не знайдено.'], Response::HTTP_BAD_REQUEST);
        }

        $this->offerService->removePhoto($offer, $path);

        return new JsonResponse(['count' => count($offer->getPhotos())]);
    }
}
