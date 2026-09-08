<?php

namespace App\Telegram\ServiceOffer\Command;

use App\Entity\Account;
use App\Entity\ServiceOffer;
use App\Repository\ServiceOfferRepository;
use App\Service\RentalListingService;
use App\Service\ServiceOfferService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaPhoto;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "🛠 Послуги" — who in the house does what, plus the author's controls for
 * their own advert. Callbacks:
 *   services-menu        — render the list
 *   svc:view:<id>        — one offer's card
 *   svc:page:<n>         — paging
 *   svc:pic:<id>:<n>     — swap the card's picture (the ⬅️/➡️ arrows)
 *   svc:photos:<id>      — hand the author a one-shot link to the photo upload page
 *   svc:contact:<id>     — relay the caller's contact to an author without a @username
 *   svc:phone:<id>       — same, with the caller's own number attached (they consented)
 *   svc:extend:<id>      — author confirms the offer is still current (+30 days)
 *   svc:remove:<id>      — author takes their offer down
 *
 * Publishing itself lives in the ServicePublish conversation.
 *
 * **Reading is for linked residents only** — the opposite call to the rental board, and
 * deliberately so. A flat advertisement wants every reader it can get, and the newcomer
 * browsing it is often exactly the person looking to move in. This list is the other way
 * round: it is neighbours offering neighbours their trade, it names which flat each of
 * them lives in, and an unverified stranger reading it gains nothing the house wants them
 * to have. Same call as the debtors' board and the complaints register.
 */
class ServiceMenuCommand
{
    public const MENU_CALLBACK = 'services-menu';

    /** The "2/3" counter between the arrows is a label, not a button — it does nothing. */
    private const NOOP_CALLBACK = 'svc:noop';

    /** One button per offer; details live in the offer's own card. */
    private const PAGE_SIZE = 10;

    public function __construct(
        private TelegramUserService $telegramUserService,
        private ServiceOfferService $offerService,
        private ServiceOfferRepository $offers,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->isCallbackQuery() ? ($bot->callbackQuery()->data ?? '') : '';

        if ($data === self::NOOP_CALLBACK) {
            $bot->answerCallbackQuery();

            return;
        }

        if (str_starts_with($data, 'svc:view:')) {
            $this->renderCard($bot, (int)substr($data, strlen('svc:view:')));

            return;
        }

        if (str_starts_with($data, 'svc:pic:')) {
            [$id, $index] = array_pad(explode(':', substr($data, strlen('svc:pic:'))), 2, '0');
            $this->showPhoto($bot, (int)$id, (int)$index);

            return;
        }

        if (str_starts_with($data, 'svc:photos:')) {
            $this->photoLink($bot, (int)substr($data, strlen('svc:photos:')));

            return;
        }

        if (str_starts_with($data, 'svc:page:')) {
            $this->renderMenu($bot, edit: true, page: (int)substr($data, strlen('svc:page:')));

            return;
        }

        if (str_starts_with($data, 'svc:contact:')) {
            $this->contact($bot, (int)substr($data, strlen('svc:contact:')));

            return;
        }

        if (str_starts_with($data, 'svc:phone:')) {
            $this->contact($bot, (int)substr($data, strlen('svc:phone:')), sharePhone: true);

            return;
        }

        if (str_starts_with($data, 'svc:extend:')) {
            $this->extend($bot, (int)substr($data, strlen('svc:extend:')));

            return;
        }

        if (str_starts_with($data, 'svc:remove:')) {
            $this->remove($bot, (int)substr($data, strlen('svc:remove:')));

            return;
        }

        $this->renderMenu($bot, edit: $bot->isCallbackQuery());
    }

    private function currentAccount(Nutgram $bot): ?Account
    {
        $user = $this->telegramUserService->getCurrentUser();

        return $user ? $this->telegramUserService->resolveAccount($user) : null;
    }

    private function renderMenu(Nutgram $bot, bool $edit, ?string $notice = null, int $page = 1): void
    {
        $account = $this->currentAccount($bot);

        // Not a silent hide: somebody who has opened the bot but has not been linked yet
        // is told what this is and what to do about it. The usual mark-and-explain rule —
        // the rental board is the documented exception, not this.
        if (!$account instanceof Account) {
            $this->respond(
                $bot,
                $edit,
                "🛠 <b>Послуги</b>\n\n"
                . "Це список сусідів, які пропонують свої послуги — ремонт, електрика, "
                . "манікюр, репетиторство. Він доступний лише підтвердженим мешканцям "
                . "будинку, бо в кожному оголошенні вказано квартиру автора.\n\n"
                . 'Щоб підтвердити себе, надішліть свій номер телефону: /phone',
                InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
            );

            return;
        }

        $user = $this->telegramUserService->getCurrentUser();
        $offers = $this->offerService->activeOffers();
        $mine = $this->offerService->activeForAuthor($user);

        $lines = [];

        if ($notice) {
            $lines[] = $notice;
            $lines[] = '';
        }

        $lines[] = '🛠 <b>Послуги</b>';
        $lines[] = '';

        $markup = InlineKeyboardMarkup::make();

        if (!$offers) {
            $lines[] = 'Тут поки порожньо.';
            $lines[] = '';
            $lines[] = '<i>Якщо ви щось умієте — плитка, електрика, манікюр, репетиторство, '
                . 'вигул собак — розкажіть про це сусідам тут. Це швидше, ніж питати в чаті, '
                . 'і не губиться через тиждень.</i>';
        } else {
            $pages = max(1, (int)ceil(count($offers) / self::PAGE_SIZE));
            $page = max(1, min($page, $pages));
            $shown = array_slice($offers, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

            $lines[] = 'Оберіть послугу, щоб побачити деталі та контакт.';

            $anyPhotos = false;

            foreach ($shown as $offer) {
                $own = $mine && $offer->getId() === $mine->getId();
                $anyPhotos = $anyPhotos || $offer->hasPhotos();

                $markup->addRow(InlineKeyboardButton::make(
                    $this->offerService->buttonLabel($offer, $own),
                    callback_data: 'svc:view:' . $offer->getId(),
                ));
            }

            $legend = [];

            if ($mine) {
                $legend[] = '<i>Ваше оголошення позначене 📌 — відкрийте його, щоб змінити '
                    . 'текст, номер, фото або зняти з публікації.</i>';
            }

            if ($anyPhotos) {
                $legend[] = '<i>Оголошення з фото позначені 📷.</i>';
            }

            if ($legend) {
                $lines[] = '';
                $lines = array_merge($lines, $legend);
            }

            if ($pages > 1) {
                $nav = [];

                if ($page > 1) {
                    $nav[] = InlineKeyboardButton::make('⬅️', callback_data: 'svc:page:' . ($page - 1));
                }

                $nav[] = InlineKeyboardButton::make(
                    sprintf('%d/%d', $page, $pages),
                    callback_data: self::NOOP_CALLBACK,
                );

                if ($page < $pages) {
                    $nav[] = InlineKeyboardButton::make('➡️', callback_data: 'svc:page:' . ($page + 1));
                }

                $markup->addRow(...$nav);
            }
        }

        if ($mine) {
            // Your own advert is already in the list above, marked 📌 — but only somebody
            // who has read the legend knows that tapping it is where «змінити» and
            // «зняти» live. Spelling it out on its own row costs one line and removes the
            // guess; on a second page it is also the only way to reach your own card
            // without hunting for it.
            $markup->addRow(InlineKeyboardButton::make(
                '📌 Моє оголошення (змінити / зняти)',
                callback_data: 'svc:view:' . $mine->getId(),
            ));
        } else {
            $markup->addRow(InlineKeyboardButton::make(
                '➕ Пропоную послугу',
                callback_data: ServicePublish::START_CALLBACK,
            ));
        }

        $markup->addRow(StartCommand::homeButton());

        $this->respond($bot, $edit, implode("\n", $lines), $markup);
    }

    /**
     * One offer, in full.
     *
     * The author's own card carries the management controls instead of a "write to me"
     * button — they cannot be interested in their own service.
     */
    private function renderCard(Nutgram $bot, int $offerId, int $index = 0): void
    {
        $offer = $this->liveOffer($offerId);

        if (!$offer) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Це оголошення вже неактуальне.');

            return;
        }

        $index = $this->normaliseIndex($offer, $index);

        $lines = [$this->offerService->describe($offer)];
        $markup = InlineKeyboardMarkup::make();

        $this->addPhotoNav($markup, $offer, $index);
        $this->addCardControls($bot, $markup, $offer, $lines);

        $text = implode("\n", $lines);

        if ($offer->hasPhotos() && $this->sendPhotoCard($bot, $offer, $index, $text, $markup)) {
            return;
        }

        $this->respond($bot, edit: true, text: $text, markup: $markup);
    }

    /**
     * Everything under the picture except the arrows. Shared with showPhoto() so leafing
     * through photos cannot quietly produce a card with a different keyboard than the one
     * you opened.
     *
     * @param string[] $lines caption lines, appended to for the author's contact hint
     */
    private function addCardControls(
        Nutgram $bot,
        InlineKeyboardMarkup $markup,
        ServiceOffer $offer,
        array &$lines,
    ): void {
        if ($this->ownsOffer($bot, $offer)) {
            $lines[] = '';
            $lines[] = $this->offerService->contactHint($offer);

            $photoCount = count($offer->getPhotos());

            $markup->addRow(InlineKeyboardButton::make(
                $photoCount === 0
                    ? '📷 Додати фото робіт'
                    : sprintf('📷 Керувати фото (%d/%d)', $photoCount, ServiceOffer::PHOTOS_MAX),
                callback_data: 'svc:photos:' . $offer->getId(),
            ));

            $markup->addRow(
                InlineKeyboardButton::make('✏️ Змінити', callback_data: ServicePublish::START_CALLBACK),
                InlineKeyboardButton::make('🚫 Зняти', callback_data: 'svc:remove:' . $offer->getId()),
            );
        } else {
            $markup->addRow($this->offerService->contactButton($offer));
        }

        $markup->addRow(
            InlineKeyboardButton::make('⬅️ До списку', callback_data: self::MENU_CALLBACK),
            StartCommand::homeButton(),
        );
    }

    /**
     * A card with a picture is a different kind of Telegram message: a text message cannot
     * be edited into a photo message, and a media group cannot carry an inline keyboard at
     * all. So the index message is deleted and replaced by a photo with the card as its
     * caption — one message in the chat either way, and the keyboard survives.
     *
     * @return bool false when the picture could not be sent, so the caller falls back to
     *              the plain text card rather than showing the resident nothing.
     */
    private function sendPhotoCard(
        Nutgram $bot,
        ServiceOffer $offer,
        int $index,
        string $caption,
        InlineKeyboardMarkup $markup,
    ): bool {
        $abs = $this->photoPath($offer, $index);

        if (!$abs) {
            return false;
        }

        $stream = @fopen($abs, 'rb');

        if ($stream === false) {
            return false;
        }

        try {
            $bot->sendPhoto(
                photo: InputFile::make($stream, basename($abs)),
                caption: $caption,
                parse_mode: ParseMode::HTML,
                reply_markup: $markup,
            );
        } catch (\Throwable $e) {
            // Falling back silently is how a wrong InputFile import shipped unnoticed on
            // the rental board: the card kept rendering as text and nothing said why.
            $this->logger->error('service photo card failed, falling back to text', [
                'offer_id' => $offer->getId(),
                'path' => $abs,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        // Only now — if sending failed we still have the message the user was looking at.
        try {
            $bot->deleteMessage($bot->chatId(), $bot->messageId());
        } catch (\Throwable) {
            // A message older than 48h cannot be deleted; leaving it is harmless.
        }

        return true;
    }

    /**
     * ⬅️ 2/3 ➡️ under the card — the picture is swapped in place with editMessageMedia,
     * which is the one edit a photo message accepts, so the whole offer stays exactly one
     * message. Skipped for a single photo: there is nothing to leaf through.
     */
    private function addPhotoNav(InlineKeyboardMarkup $markup, ServiceOffer $offer, int $index): void
    {
        $total = count($offer->getPhotos());

        if ($total < 2) {
            return;
        }

        $prev = ($index - 1 + $total) % $total;
        $next = ($index + 1) % $total;

        $markup->addRow(
            InlineKeyboardButton::make('⬅️', callback_data: sprintf('svc:pic:%d:%d', $offer->getId(), $prev)),
            InlineKeyboardButton::make(
                sprintf('🖼 %d/%d', $index + 1, $total),
                callback_data: self::NOOP_CALLBACK,
            ),
            InlineKeyboardButton::make('➡️', callback_data: sprintf('svc:pic:%d:%d', $offer->getId(), $next)),
        );
    }

    /** An arrow was tapped: put photo #$index into the card that is already on screen. */
    private function showPhoto(Nutgram $bot, int $offerId, int $index): void
    {
        $offer = $this->liveOffer($offerId);

        if (!$offer) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Це оголошення вже неактуальне.');

            return;
        }

        $index = $this->normaliseIndex($offer, $index);
        $abs = $this->photoPath($offer, $index);
        $stream = $abs ? @fopen($abs, 'rb') : false;

        if ($stream === false) {
            // The file is gone but the card is fine: re-render rather than leave the
            // resident tapping an arrow that does nothing.
            $bot->answerCallbackQuery(text: '⚠️ Це фото більше недоступне.');
            $this->renderCard($bot, $offerId);

            return;
        }

        $lines = [$this->offerService->describe($offer)];
        $markup = InlineKeyboardMarkup::make();

        $this->addPhotoNav($markup, $offer, $index);
        $this->addCardControls($bot, $markup, $offer, $lines);

        $bot->answerCallbackQuery();

        try {
            $bot->editMessageMedia(
                media: InputMediaPhoto::make(
                    media: InputFile::make($stream, basename((string)$abs)),
                    caption: implode("\n", $lines),
                    parse_mode: ParseMode::HTML,
                ),
                reply_markup: $markup,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('service photo swap failed, re-rendering the card', [
                'offer_id' => $offer->getId(),
                'index' => $index,
                'error' => $e->getMessage(),
            ]);

            $this->renderCard($bot, $offerId, $index);
        }
    }

    /** Photo index, wrapped into range — an offer can lose a photo while a card is open. */
    private function normaliseIndex(ServiceOffer $offer, int $index): int
    {
        $total = count($offer->getPhotos());

        if ($total < 1) {
            return 0;
        }

        return (($index % $total) + $total) % $total;
    }

    /** Readable absolute path of photo #$index, or null. */
    private function photoPath(ServiceOffer $offer, int $index): ?string
    {
        $path = $offer->getPhotos()[$index] ?? null;
        $abs = $path ? $this->offerService->absolutePath($path) : null;

        return $abs && is_readable($abs) ? $abs : null;
    }

    /**
     * Photos are uploaded on the web, not sent to the bot — see ServiceOffer::$photo_token
     * for why that invariant cannot be relaxed. All this does is mint a link and hand it
     * over.
     */
    private function photoLink(Nutgram $bot, int $offerId): void
    {
        $offer = $this->liveOffer($offerId);

        if (!$offer || !$this->ownsOffer($bot, $offer)) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Оголошення не знайдено.');

            return;
        }

        $token = $this->offerService->issueToken($offer);

        $url = $this->urlGenerator->generate(
            'service_photo_page',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $this->respond(
            $bot,
            edit: true,
            text: "📷 <b>Фото ваших робіт</b>\n\n"
                . 'Відкрийте сторінку і виберіть до ' . ServiceOffer::PHOTOS_MAX
                . " фото з галереї телефону — вони одразу з'являться в оголошенні. "
                . "Показане «як вийшло» переконує краще за будь-який опис.\n\n"
                . '<i>Посилання діє ' . ServiceOffer::PHOTO_TOKEN_TTL_HOURS
                . ' години і лише для вашого оголошення. '
                . 'Фото альтанки сюди не вантажте — їх, як і раніше, надсилайте прямо в бот.</i>',
            // web_app, not a plain url: opened this way the page runs inside Telegram and
            // closes itself when the author is done, dropping them back in the chat.
            markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('📷 Відкрити сторінку', web_app: WebAppInfo::make($url)))
                ->addRow(InlineKeyboardButton::make('⬅️ До оголошення', callback_data: 'svc:view:' . $offer->getId()))
                ->addRow(StartCommand::homeButton()),
        );
    }

    /**
     * The author has no @username, so the interested neighbour can't be handed a t.me
     * link. Push their contact to the author instead and say plainly what happens next.
     */
    private function contact(Nutgram $bot, int $offerId, bool $sharePhone = false): void
    {
        $offer = $this->liveOffer($offerId);
        $user = $this->telegramUserService->getCurrentUser();

        if (!$offer || !$user) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Це оголошення вже неактуальне.');

            return;
        }

        if ($offer->getAuthor() && $user->getId() === $offer->getAuthor()->getId()) {
            $this->renderMenu($bot, edit: true, notice: 'ℹ️ Це ваше власне оголошення.');

            return;
        }

        // Neither side has a @username: relaying a name alone leaves the author unable to
        // answer. Offer to pass the number instead — but ask first, this is their data.
        $ownPhone = RentalListingService::formatPhone($user->getPhoneNumber());

        if (!$sharePhone && !$user->getUsername() && $ownPhone !== null) {
            $this->askPhoneConsent($bot, $offer, $ownPhone);

            return;
        }

        if (!$this->offerService->relayContact($offer, $user, $sharePhone)) {
            $this->renderMenu(
                $bot,
                edit: true,
                notice: '⚠️ Не вдалося сповістити автора — він не користується ботом.',
            );

            return;
        }

        if ($sharePhone) {
            $notice = '✅ Автору передано ваш номер ' . self::esc($ownPhone ?? '') . ' — очікуйте дзвінка.';
        } elseif ($user->getUsername()) {
            $notice = '✅ Автора сповіщено — він напише вам у Telegram.';
        } else {
            $notice = '✅ Автора сповіщено. Щоб він міг вам відповісти, додайте @username '
                . 'у налаштуваннях Telegram.';
        }

        $this->renderMenu($bot, edit: true, notice: $notice);
    }

    /** Their number, their call — shown in full, with a way out that isn't a dead end. */
    private function askPhoneConsent(Nutgram $bot, ServiceOffer $offer, string $phone): void
    {
        $this->respond(
            $bot,
            edit: true,
            text: "📞 <b>Як автор з вами зв'яжеться?</b>\n\n"
                . 'У вас не налаштований @username, тому написати вам у Telegram він не зможе. '
                . 'Передати йому ваш номер <b>' . self::esc($phone) . "</b>?\n\n"
                . '<i>Номер побачить лише автор цього оголошення, не весь будинок.</i>',
            markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    '📞 Так, передати номер',
                    callback_data: 'svc:phone:' . $offer->getId(),
                ))
                ->addRow(InlineKeyboardButton::make('⬅️ Назад', callback_data: self::MENU_CALLBACK)),
        );
    }

    private function extend(Nutgram $bot, int $offerId): void
    {
        $offer = $this->liveOffer($offerId);

        if (!$offer || !$this->ownsOffer($bot, $offer)) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Оголошення не знайдено.');

            return;
        }

        $this->offerService->extend($offer);

        $this->renderMenu(
            $bot,
            edit: true,
            notice: '✅ Оголошення продовжено до ' . $offer->getExpiresAt()->format('d.m.Y') . '.',
        );
    }

    private function remove(Nutgram $bot, int $offerId): void
    {
        $offer = $this->liveOffer($offerId);

        if (!$offer || !$this->ownsOffer($bot, $offer)) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Оголошення не знайдено.');

            return;
        }

        $this->offerService->withdraw($offer);

        $this->renderMenu($bot, edit: true, notice: '🚫 Оголошення знято зі списку.');
    }

    private function liveOffer(int $offerId): ?ServiceOffer
    {
        if ($offerId <= 0) {
            return null;
        }

        $offer = $this->offers->find($offerId);

        return $offer && $offer->isActive() ? $offer : null;
    }

    /**
     * The offer belongs to the person who does the work, not to the household.
     *
     * This is the one ownership rule in the bot that is NOT "any member of the account":
     * a booking, a ballot and a rental listing are the flat's, but father's electrical
     * work is not his daughter's to retitle. The account is the fallback only when the
     * author's row is gone (SET NULL on delete), so a household is never left with an
     * advert nobody can take down.
     */
    private function ownsOffer(Nutgram $bot, ServiceOffer $offer): bool
    {
        $user = $this->telegramUserService->getCurrentUser();

        if (!$user) {
            return false;
        }

        $author = $offer->getAuthor();

        if ($author) {
            return $author->getId() === $user->getId();
        }

        $account = $this->currentAccount($bot);

        return $account !== null && $account->getId() === $offer->getAccount()->getId();
    }

    /**
     * $edit fails when the message on screen is a photo card — Telegram will not turn a
     * picture back into text. Then the card is deleted and a text message takes its place,
     * so leaving an offer never leaves its photo hanging in the chat above.
     */
    private function respond(Nutgram $bot, bool $edit, string $text, InlineKeyboardMarkup $markup): void
    {
        if ($edit) {
            try {
                $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);

                return;
            } catch (\Throwable) {
                try {
                    $bot->deleteMessage($bot->chatId(), $bot->messageId());
                } catch (\Throwable) {
                    // Older than 48h, or already gone — the fresh message below still lands.
                }
            }
        }

        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
