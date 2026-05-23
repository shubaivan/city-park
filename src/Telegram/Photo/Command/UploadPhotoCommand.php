<?php

namespace App\Telegram\Photo\Command;

use App\Entity\PhotoUploadRequest;
use App\Repository\PhotoUploadRequestRepository;
use App\Service\PavilionPhotoService;
use App\Service\TelegramUserService;
use App\Service\UkDateFormatter;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

class UploadPhotoCommand extends Command
{
    protected string $command = 'photoEvent';
    protected ?string $description = 'Photo upload after pavilion booking';

    public function __construct(
        private TelegramUserService $telegramUserService,
        private PhotoUploadRequestRepository $requestRepository,
        private PavilionPhotoService $photoService,
        private LoggerInterface $logger,
        $callable = null,
        ?string $command = null,
    ) {
        parent::__construct($callable, $command);
    }

    public function handle(Nutgram $bot): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $account = $user?->getAccount();

        if (!$account) {
            $bot->sendMessage(
                text: '📷 Дякуємо, але у вас немає прив\'язаного аккаунту, тож завантаження зараз не потрібне.',
            );
            return;
        }

        $open = $this->requestRepository->findOpenForAccount($account);
        $open = array_values(array_filter($open, fn(PhotoUploadRequest $r) => $r->isOpen()));

        if (!$open) {
            $bot->sendMessage(
                text: '📷 Дякуємо! Наразі від вас не очікується завантаження фото.',
            );
            return;
        }

        usort($open, fn(PhotoUploadRequest $a, PhotoUploadRequest $b) =>
            $a->getSessionStartAt() <=> $b->getSessionStartAt());
        $request = $open[0];

        try {
            $this->saveLargestPhoto($bot, $request);
        } catch (\Throwable $t) {
            $this->logger->error('Photo upload failed: ' . $t->getMessage(), [
                'exception' => $t,
                'request_id' => $request->getId(),
            ]);
            $bot->sendMessage(
                text: '⚠️ Не вдалося зберегти фото. Спробуйте надіслати ще раз.',
            );
            return;
        }

        $start = $request->getSessionStartAt();
        $bot->sendMessage(
            text: sprintf(
                "✅ <b>Дякуємо! Фото отримано.</b>\n\n🏠 Альтанка: <b>%s</b>\n📅 <b>%s</b>\n⏰ <b>%s</b>",
                $request->getPavilion() === 1 ? 'Перша' : 'Друга',
                UkDateFormatter::dayDate($start),
                UkDateFormatter::time($start),
            ),
            parse_mode: ParseMode::HTML,
        );

        $remaining = array_slice($open, 1);
        if ($remaining) {
            $bot->sendMessage(
                text: sprintf(
                    'У вас ще %d очікування завантаження фото. Будь ласка, надішліть наступне фото.',
                    count($remaining),
                ),
            );
        }
    }

    private function saveLargestPhoto(Nutgram $bot, PhotoUploadRequest $request): void
    {
        $message = $bot->message();
        $photos = $message?->photo ?? [];
        if (!$photos) {
            throw new \RuntimeException('No photo array in message');
        }

        // Telegram sends multiple sizes; take the largest.
        $largest = end($photos);
        $fileId = $largest->file_id;

        $file = $bot->getFile($fileId);
        if (!$file) {
            throw new \RuntimeException('Telegram getFile returned null');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pavphoto_');
        if ($tmp === false) {
            throw new \RuntimeException('Failed to allocate temp file');
        }

        $ok = $bot->downloadFile($file, $tmp);
        if (!$ok) {
            @unlink($tmp);
            throw new \RuntimeException('downloadFile failed');
        }

        $ext = 'jpg';
        if (!empty($file->file_path)) {
            $ext = pathinfo($file->file_path, PATHINFO_EXTENSION) ?: 'jpg';
        }

        $this->photoService->attachPhoto(
            $request,
            $this->telegramUserService->getCurrentUser(),
            $tmp,
            $fileId,
            $ext,
        );
    }
}
