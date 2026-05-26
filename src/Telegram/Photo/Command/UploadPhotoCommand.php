<?php

namespace App\Telegram\Photo\Command;

use App\Entity\PhotoUploadRequest;
use App\Repository\PhotoUploadRequestRepository;
use App\Service\PavilionPhotoService;
use App\Service\SchedulePavilionService;
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
                text: '📷 <b>Фото вже отримано.</b> Достатньо одного фото на сесію — наступне фото знадобиться лише після нового бронювання.',
                parse_mode: ParseMode::HTML,
            );
            return;
        }

        $now = SchedulePavilionService::createNewDate();
        $active = array_values(array_filter(
            $open,
            fn(PhotoUploadRequest $r) => $this->photoService->isUploadStillAllowed($r, $now),
        ));

        if (!$active) {
            $bot->sendMessage(
                text: '⏰ <b>Час на завантаження фото минув.</b>' . "\n\n"
                    . sprintf(
                        'Фото приймається лише протягом %d хв після блокування. '
                        . 'Для розблокування зверніться до Аліни Бухгалтера — +380 93 658 32 02.',
                        PavilionPhotoService::UPLOAD_GRACE_AFTER_BLOCK_MIN,
                    ),
                parse_mode: ParseMode::HTML,
            );
            return;
        }

        usort($active, fn(PhotoUploadRequest $a, PhotoUploadRequest $b) =>
            $a->getSessionStartAt() <=> $b->getSessionStartAt());
        $request = $active[0];

        $wasBlocked = $request->getBlockedAt() !== null && $account->isActive() === false;

        try {
            $this->saveLargestPhoto($bot, $request);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $t) {
            // Race: another photo for this session arrived between our request lookup and save.
            $bot->sendMessage(
                text: '📷 <b>Фото вже отримано.</b> Достатньо одного фото на сесію.',
                parse_mode: ParseMode::HTML,
            );
            return;
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

        if ($wasBlocked && $account->isActive() === true) {
            $bot->sendMessage(
                text: '✅ <b>Доступ до бронювання відновлено</b> — оскільки ви завантажили фото, ми зняли блокування з вашого акаунту. Можна знову бронювати.',
                parse_mode: ParseMode::HTML,
            );
        }

        // Re-query: attachPhoto auto-resolves sibling open requests covered by the
        // photo's window, so the post-upload "still pending" count must reflect that.
        $stillOpen = array_filter(
            $this->requestRepository->findOpenForAccount($account),
            fn(PhotoUploadRequest $r) => $r->isOpen() && $this->photoService->isUploadStillAllowed($r, $now),
        );
        if ($stillOpen) {
            $bot->sendMessage(
                text: sprintf(
                    'У вас ще %d очікування завантаження фото. Будь ласка, надішліть наступне фото.',
                    count($stillOpen),
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
