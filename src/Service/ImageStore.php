<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Turning an uploaded file into a picture we are willing to serve.
 *
 * Extracted so the rental noticeboard and the complaints register share one copy. The
 * rules here are the security-relevant half of both features, and two copies of them
 * means the day one is fixed the other silently is not.
 *
 * Everything is re-encoded through GD even when it arrives as a valid JPEG. That is what
 * strips EXIF — including the GPS tag a phone puts on a photo taken at home — and what
 * guarantees the bytes we write are an image rather than something dressed as one.
 */
class ImageStore
{
    /** Prod `upload_max_filesize` is 2M; the browser downscales first, this is the backstop. */
    private const MAX_BYTES = 4 * 1024 * 1024;

    private const MAX_EDGE = 1600;

    private const QUALITY = 82;

    public function __construct(
        private LoggerInterface $logger,
        private string $publicDir,
    ) {}

    /**
     * @param string $relativeDir e.g. "uploads/complaint-photos" — the year/month is added here
     * @return string|null public path ("/uploads/…/ab12.jpg"), or null with $error set
     */
    public function store(UploadedFile $file, string $relativeDir, ?string &$error = null): ?string
    {
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

        $relDir = trim($relativeDir, '/') . '/' . date('Y/m');
        $absDir = rtrim($this->publicDir, '/') . '/' . $relDir;

        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            imagedestroy($image);
            $error = 'Не вдалося зберегти файл.';
            $this->logger->error('image store: cannot create dir', ['dir' => $absDir]);

            return null;
        }

        $name = bin2hex(random_bytes(8)) . '.jpg';
        $ok = imagejpeg($image, $absDir . '/' . $name, self::QUALITY);
        imagedestroy($image);

        if (!$ok) {
            $error = 'Не вдалося зберегти файл.';

            return null;
        }

        return '/' . $relDir . '/' . $name;
    }

    /**
     * Absolute path of a stored picture, or null when the argument is not one of ours.
     *
     * The prefix check is the guard against a crafted path reaching unlink(): callers pass
     * values that came back over HTTP.
     */
    public function absolutePath(?string $publicPath, string $relativeDir): ?string
    {
        $publicPath = (string)$publicPath;
        $prefix = '/' . trim($relativeDir, '/') . '/';

        if (!str_starts_with($publicPath, $prefix) || str_contains($publicPath, '..')) {
            return null;
        }

        return rtrim($this->publicDir, '/') . $publicPath;
    }

    public function delete(?string $publicPath, string $relativeDir): void
    {
        $abs = $this->absolutePath($publicPath, $relativeDir);

        if ($abs !== null && is_file($abs)) {
            @unlink($abs);
        }
    }

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
