<?php

namespace App\Controller;

use App\Entity\RentalListing;
use App\Service\RentalPhotoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The apartment-photo upload page, opened from a one-shot link the bot hands the owner.
 *
 * Authorisation is the token and nothing else — there is no login here and residents have
 * no accounts on the site. That is acceptable because the token is random, short-lived
 * (RentalListing::PHOTO_TOKEN_TTL_HOURS), re-issued on every request from the bot, and
 * grants exactly one capability: adding or removing photos on one listing that is already
 * public to the whole house. It exposes nothing a reader of the listing cannot see.
 *
 * Why photos are not simply sent to the bot: see RentalPhotoService's class comment.
 */
class RentalPhotoController extends AbstractController
{
    public function __construct(private RentalPhotoService $photoService) {}

    #[Route('/rent/photo/{token}', name: 'rental_photo_page', methods: ['GET'])]
    public function page(string $token): Response
    {
        $listing = $this->photoService->findByToken($token);

        if (!$listing) {
            return $this->render('rental/photo_expired.html.twig', [], new Response('', Response::HTTP_NOT_FOUND));
        }

        return $this->render('rental/photo_upload.html.twig', [
            'listing' => $listing,
            'token' => $token,
            'max' => RentalListing::PHOTOS_MAX,
        ]);
    }

    #[Route('/rent/photo/{token}/upload', name: 'rental_photo_upload', methods: ['POST'])]
    public function upload(string $token, Request $request): JsonResponse
    {
        $listing = $this->photoService->findByToken($token);

        if (!$listing) {
            return new JsonResponse(['error' => 'Посилання застаріло.'], Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('photo');

        if (!$file) {
            return new JsonResponse(['error' => 'Файл не надійшов.'], Response::HTTP_BAD_REQUEST);
        }

        $error = null;
        $path = $this->photoService->store($listing, $file, $error);

        if (!$path) {
            return new JsonResponse(['error' => $error ?? 'Не вдалося зберегти.'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'path' => $path,
            'count' => count($listing->getPhotos()),
            'max' => RentalListing::PHOTOS_MAX,
        ]);
    }

    #[Route('/rent/photo/{token}/delete', name: 'rental_photo_delete', methods: ['POST'])]
    public function delete(string $token, Request $request): JsonResponse
    {
        $listing = $this->photoService->findByToken($token);

        if (!$listing) {
            return new JsonResponse(['error' => 'Посилання застаріло.'], Response::HTTP_NOT_FOUND);
        }

        $path = (string)$request->request->get('path');

        if (!in_array($path, $listing->getPhotos(), true)) {
            return new JsonResponse(['error' => 'Фото не знайдено.'], Response::HTTP_BAD_REQUEST);
        }

        $this->photoService->remove($listing, $path);

        return new JsonResponse(['count' => count($listing->getPhotos())]);
    }
}
