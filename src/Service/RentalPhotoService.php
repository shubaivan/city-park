<?php

namespace App\Service;

use App\Entity\RentalListing;
use App\Repository\RentalListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Apartment photos for a rental listing — optional, and deliberately NOT uploaded
 * through Telegram.
 *
 * The reason is the photo-obligation cron. `pavilion:photo:check` runs every 20 minutes
 * and only then materialises a PhotoUploadRequest, so for up to 20 minutes after a
 * booking ends there is no open request at all. Any in-bot rule of the shape "no open
 * obligation, therefore this picture must be a flat" would swallow the pavilion photo of
 * the resident who sent it immediately — the most conscientious one — and the cron would
 * then block them for evidence they had already sent. That is the August incident coming
 * back through a different door.
 *
 * Keeping this channel on the web means a picture sent to the bot is always, without a
 * rule to get wrong, pavilion evidence. PhotoUploadFlow is not touched by this feature.
 */
class RentalPhotoService
{
    public const PUBLIC_DIR = 'uploads/rental-photos';

    /** Server-side cap. The page downscales in the browser first, so this is a backstop. */
    private const MAX_BYTES = 4 * 1024 * 1024;

    /** Long edge after the safety re-encode. Also what the browser aims for. */
    private const MAX_EDGE = 1600;

    public function __construct(
        private RentalListingRepository $listingRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $publicDir,
    ) {}

    /**
     * Issue (or refresh) the upload link for a listing.
     *
     * Regenerated on every request so a link the owner forwarded to someone yesterday
     * stops working as soon as they ask for a new one.
     */
    public function issueToken(RentalListing $listing): string
    {
        $token = bin2hex(random_bytes(16));

        $listing->setPhotoToken($token);
        $listing->setPhotoTokenExpiresAt(
            (clone RentalListingService::now())
                ->modify('+' . RentalListing::PHOTO_TOKEN_TTL_HOURS . ' hours')
        );

        $this->em->flush();

        return $token;
    }

    public function findByToken(?string $token): ?RentalListing
    {
        if (!$token || !preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }

        $listing = $this->listingRepository->findOneBy(['photo_token' => $token]);

        if (!$listing || !$listing->isActive()) {
            return null;
        }

        return $listing->isPhotoTokenValid(RentalListingService::now()) ? $listing : null;
    }

    /**
     * Store one uploaded picture.
     *
     * Everything is re-encoded through GD even when it arrives as a valid JPEG: that is
     * what strips EXIF (including GPS) and guarantees the bytes we serve are an image and
     * not something dressed as one.
     *
     * @return string|null the public path, or null with $error set
     */
    public function store(RentalListing $listing, UploadedFile $file, ?string &$error = null): ?string
    {
        if (count($listing->getPhotos()) >= RentalListing::PHOTOS_MAX) {
            $error = 'Більше ' . RentalListing::PHOTOS_MAX . ' фото не можна.';
            return null;
        }

        if ($file->getSize() > self::MAX_BYTES) {
            $error = 'Файл завеликий.';
            return null;
        }

        $image = @imagecreatefromstring((string)file_get_contents($file->getPathname()));
        if ($image === false) {
            $error = 'Це не схоже на зображення.';
            return null;
        }

        $image = $this->downscale($image);

        $relDir = self::PUBLIC_DIR . '/' . date('Y/m');
        $absDir = rtrim($this->publicDir, '/') . '/' . $relDir;

        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            imagedestroy($image);
            $error = 'Не вдалося зберегти файл.';
            $this->logger->error('rental photo: cannot create dir', ['dir' => $absDir]);
            return null;
        }

        $name = bin2hex(random_bytes(8)) . '.jpg';
        $ok = imagejpeg($image, $absDir . '/' . $name, 82);
        imagedestroy($image);

        if (!$ok) {
            $error = 'Не вдалося зберегти файл.';
            return null;
        }

        $public = '/' . $relDir . '/' . $name;

        $listing->setPhotos([...$listing->getPhotos(), $public]);
        $this->em->flush();

        $this->logger->info('rental photo stored', [
            'listing_id' => $listing->getId(),
            'path' => $public,
        ]);

        return $public;
    }

    public function remove(RentalListing $listing, string $publicPath): void
    {
        $photos = array_values(array_filter(
            $listing->getPhotos(),
            static fn (string $p): bool => $p !== $publicPath,
        ));

        $listing->setPhotos($photos);
        $this->em->flush();

        $this->deleteFile($publicPath);
    }

    /** Called when a listing is closed for good, so files don't outlive the listing. */
    public function purge(RentalListing $listing): void
    {
        foreach ($listing->getPhotos() as $path) {
            $this->deleteFile($path);
        }

        $listing->setPhotos([]);
        $listing->setPhotoToken(null);
        $listing->setPhotoTokenExpiresAt(null);
    }

    /** Absolute path of a stored photo, or null when it points outside our upload dir. */
    public function absolutePath(string $publicPath): ?string
    {
        $rel = ltrim($publicPath, '/');

        if (!str_starts_with($rel, self::PUBLIC_DIR . '/')) {
            return null;
        }

        $abs = rtrim($this->publicDir, '/') . '/' . $rel;
        $real = realpath($abs);
        $base = realpath(rtrim($this->publicDir, '/') . '/' . self::PUBLIC_DIR);

        if ($real === false || $base === false || !str_starts_with($real, $base)) {
            return null;
        }

        return $real;
    }

    private function deleteFile(string $publicPath): void
    {
        $abs = $this->absolutePath($publicPath);

        if ($abs && is_file($abs)) {
            @unlink($abs);
        }
    }

    /** @param \GdImage $image */
    private function downscale(\GdImage $image): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $edge = max($w, $h);

        if ($edge <= self::MAX_EDGE) {
            return $image;
        }

        $scale = self::MAX_EDGE / $edge;
        $resized = imagescale($image, (int)round($w * $scale), (int)round($h * $scale));

        if ($resized === false) {
            return $image;
        }

        imagedestroy($image);

        return $resized;
    }
}
