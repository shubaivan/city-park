<?php

namespace App\Telegram\Rental\Command;

use App\Entity\RentalListing;
use App\Service\PhotoUploadFlow;
use App\Service\RentalListingService;
use App\Service\TelegramUserService;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «🎬 Додати відео» on the owner's card: the next video they send becomes the listing's.
 *
 * Unlike the photos this does come through Telegram. Photos stay out of the chat because a
 * picture sent to the bot is pavilion evidence; a video never is
 * ({@see PhotoUploadFlow::isIncomingPhoto()} looks at `message.photo` alone), and a photo
 * sent here is still handed to that flow by the guard below.
 *
 * Only the `file_id` is kept: Telegram holds the file, resending by id has no size limit,
 * and a bot can download nothing over 20 MB anyway.
 *
 * «Скасувати» is an ordinary `rent:view:<id>` button: a callback with a route of its own
 * ends a live conversation by itself.
 */
class RentalVideo extends Conversation
{
    public const KIND_VIDEO = 'video';
    public const KIND_NOTE = 'note';
    public const KIND_FILE = 'file';

    public const NOTE_REFUSED = '🎬 Відеоповідомлення («кружечок») до оголошення додати не можна. Надішліть звичайне відео.';
    public const FILE_REFUSED = '🎬 Це відео надіслано як файл. Надішліть його як звичайне відео, без «надіслати як файл».';

    protected ?string $step = 'start';

    public ?int $listingId = null;

    public function __construct(
        private TelegramUserService $telegramUserService,
        private RentalListingService $rentalService,
        private PhotoUploadFlow $photoUploadFlow,
        private ?LoggerInterface $photoLogger = null,
    ) {}

    /** The guard every multi-step conversation carries; see RentalPublish. */
    public function __invoke(Nutgram $bot, ...$parameters): mixed
    {
        if (PhotoUploadFlow::isIncomingPhoto($bot)) {
            try {
                $this->photoUploadFlow->interceptConversationPhoto(
                    $bot,
                    $this->step,
                    '📷 Ви надіслали фото — обробляємо його, додавання відео скасовано. '
                        . 'Щоб додати відео, відкрийте своє оголошення ще раз.',
                );
            } catch (\Throwable $e) {
                $this->photoLogger?->error('photo interception failed outright', [
                    'chat_id' => $bot->chatId(),
                    'error' => $e->getMessage(),
                ]);
            }

            return null;
        }

        return parent::__invoke($bot, ...$parameters);
    }

    /**
     * What kind of video an incoming message carries, or null when it carries none.
     *
     * Never for a callback: the message under a button on a video card has a video too.
     */
    public static function incomingKind(Nutgram $bot): ?string
    {
        if ($bot->isCallbackQuery()) {
            return null;
        }

        $message = $bot->message();

        return match (true) {
            $message?->video !== null => self::KIND_VIDEO,
            $message?->video_note !== null => self::KIND_NOTE,
            str_starts_with((string)$message?->document?->mime_type, 'video/') => self::KIND_FILE,
            default => null,
        };
    }

    public function start(Nutgram $bot): void
    {
        $bot->answerCallbackQuery();

        $data = (string)($bot->callbackQuery()->data ?? '');
        $this->listingId = (int)substr($data, strlen('rent:video:'));
        $listing = $this->ownListing();

        if ($listing === null) {
            $bot->sendMessage(text: '⚠️ Оголошення не знайдено.');
            $this->end();

            return;
        }

        $bot->sendMessage(
            text: sprintf(
                "🎬 <b>Відео до оголошення</b>\n\nНадішліть одне відео, до %d хвилин, просто сюди.%s",
                intdiv(RentalListing::VIDEO_MAX_SECONDS, 60),
                $listing->hasVideo() ? "\n\nНове відео замінить те, що є зараз." : '',
            ),
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'rent:view:' . $listing->getId()),
            ),
        );

        $this->next('receive');
    }

    public function receive(Nutgram $bot): void
    {
        $listing = $this->ownListing();

        if ($listing === null) {
            $bot->sendMessage(text: '⚠️ Це оголошення вже неактуальне.');
            $this->end();

            return;
        }

        $kind = self::incomingKind($bot);

        if ($kind === self::KIND_NOTE || $kind === self::KIND_FILE) {
            $bot->sendMessage(text: $kind === self::KIND_NOTE ? self::NOTE_REFUSED : self::FILE_REFUSED);

            return;
        }

        if ($kind === null) {
            $bot->sendMessage(text: '🎬 Чекаю на відео. Надішліть його сюди або натисніть «Скасувати» вище.');

            return;
        }

        $video = $bot->message()->video;

        if ($video->duration > RentalListing::VIDEO_MAX_SECONDS) {
            $bot->sendMessage(text: sprintf(
                '🎬 Це відео довше за %d хвилини. Надішліть коротше.',
                intdiv(RentalListing::VIDEO_MAX_SECONDS, 60),
            ));

            return;
        }

        $replaced = $listing->hasVideo();

        $this->rentalService->attachVideo(
            $listing,
            $video->file_id,
            $video->file_unique_id,
            $video->thumbnail?->file_id,
            $video->file_size,
            $video->duration,
            $video->mime_type,
        );

        $bot->sendMessage(
            text: $replaced ? '✅ Відео замінено.' : '✅ Відео додано. Його бачать усі, хто відкриє оголошення.',
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('🔑 Моє оголошення', callback_data: 'rent:view:' . $listing->getId()),
            ),
        );

        $this->end();
    }

    private function ownListing(): ?RentalListing
    {
        $listing = $this->rentalService->findLive((int)$this->listingId);
        $user = $this->telegramUserService->getCurrentUser();

        if ($listing === null || $user === null) {
            return null;
        }

        return $this->rentalService->isOwnObject($this->telegramUserService->resolveAccount($user), $listing->getAccount())
            ? $listing
            : null;
    }
}
