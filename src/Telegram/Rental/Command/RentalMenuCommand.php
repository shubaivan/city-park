<?php

namespace App\Telegram\Rental\Command;

use App\Entity\Account;
use App\Entity\RentalListing;
use App\Repository\RentalListingRepository;
use App\Service\RentalListingService;
use App\Service\RentalPhotoService;
use Psr\Log\LoggerInterface;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaPhoto;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "🔑 Оренда" — the house's list of apartments currently offered for rent, plus the
 * owner's controls for their own listing. Callbacks:
 *   rental-menu           — render the list
 *   rent:contact:<id>     — relay the caller's contact to an owner without a @username
 *   rent:phone:<id>       — same, with the caller's own number attached (they consented)
 *   rent:photos:<id>      — hand the owner a one-shot link to the photo upload page
 *   rent:pic:<id>:<n>     — swap the card's picture for photo #n (the ⬅️/➡️ arrows)
 *   rent:extend:<id>      — owner confirms the listing is still current (+30 days)
 *   rent:remove:<id>      — owner takes their listing down
 *
 * Publishing itself lives in the RentalPublish conversation (button "➕ Здаю квартиру").
 */
class RentalMenuCommand
{
    public const MENU_CALLBACK = 'rental-menu';

    /** The "2/3" counter between the arrows is a label, not a button — it does nothing. */
    private const NOOP_CALLBACK = 'rent:noop';

    /**
     * Listings per page in the index.
     *
     * The index is one button per listing rather than a wall of descriptions: with five
     * flats on offer the old render was a screen of text you had to scroll past to reach
     * the buttons underneath. Details live in the listing's own card (rent:view:<id>).
     */
    private const PAGE_SIZE = 10;

    public function __construct(
        private TelegramUserService $telegramUserService,
        private RentalListingService $rentalService,
        private RentalListingRepository $listingRepository,
        private RentalPhotoService $photoService,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->isCallbackQuery() ? ($bot->callbackQuery()->data ?? '') : '';

        if (str_starts_with($data, 'rent:view:')) {
            $this->renderCard($bot, (int)substr($data, strlen('rent:view:')));
            return;
        }

        if ($data === self::NOOP_CALLBACK) {
            $bot->answerCallbackQuery();
            return;
        }

        if (str_starts_with($data, 'rent:pic:')) {
            [$id, $index] = array_pad(explode(':', substr($data, strlen('rent:pic:'))), 2, '0');
            $this->showPhoto($bot, (int)$id, (int)$index);
            return;
        }

        if (str_starts_with($data, 'rent:photos:')) {
            $this->photoLink($bot, (int)substr($data, strlen('rent:photos:')));
            return;
        }

        if (str_starts_with($data, 'rent:page:')) {
            $this->renderMenu($bot, edit: true, page: (int)substr($data, strlen('rent:page:')));
            return;
        }

        if (str_starts_with($data, 'rent:contact:')) {
            $this->contact($bot, (int)substr($data, strlen('rent:contact:')));
            return;
        }

        if (str_starts_with($data, 'rent:phone:')) {
            $this->contact($bot, (int)substr($data, strlen('rent:phone:')), sharePhone: true);
            return;
        }

        if (str_starts_with($data, 'rent:extend:')) {
            $this->extend($bot, (int)substr($data, strlen('rent:extend:')));
            return;
        }

        if (str_starts_with($data, 'rent:remove:')) {
            $this->remove($bot, (int)substr($data, strlen('rent:remove:')));
            return;
        }

        $this->renderMenu($bot, edit: $bot->isCallbackQuery());
    }

    private function currentAccount(Nutgram $bot): ?Account
    {
        $user = $this->telegramUserService->getCurrentUser();
        if (!$user) {
            return null;
        }

        return $this->telegramUserService->resolveAccount($user);
    }

    private function renderMenu(Nutgram $bot, bool $edit, ?string $notice = null, int $page = 1): void
    {
        // Reading the list is open to everyone who opens the bot, confirmed by the
        // accountant or not. A listing is an advertisement — hiding it from someone who
        // has not been linked to an особовий рахунок yet only costs the owner readers,
        // and the newcomer is often exactly the person looking for a flat here.
        // Publishing still needs a confirmed account: apartment, address and area are
        // read from it. Such a reader is shown the list and nothing else — no publish
        // button, no explanation, no accountant's phone number. They came to look at
        // flats, not to be told about a restriction that does not concern them, and
        // Alina's number is not something to hand to every unlinked stranger.
        $account = $this->currentAccount($bot);

        $listings = $this->rentalService->activeListings();
        $mine = $account ? $this->rentalService->activeForAccount($account) : null;

        $lines = [];
        if ($notice) {
            $lines[] = $notice;
            $lines[] = '';
        }
        $lines[] = '🔑 <b>Здаються квартири</b>';
        $lines[] = '';

        $markup = InlineKeyboardMarkup::make();

        if (!$listings) {
            $lines[] = 'Зараз оголошень немає.';

            if ($account) {
                $lines[] = '';
                $lines[] = '<i>Якщо ви здаєте свою квартиру — розкажіть про це сусідам тут, '
                    . 'замість того щоб шукати охочих у чаті.</i>';
            }
        } else {
            $pages = max(1, (int)ceil(count($listings) / self::PAGE_SIZE));
            $page = max(1, min($page, $pages));
            $shown = array_slice($listings, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

            $lines[] = 'Оберіть квартиру, щоб побачити деталі та контакт.';

            $anyPhotos = false;

            foreach ($shown as $listing) {
                $own = $mine && $listing->getId() === $mine->getId();
                $anyPhotos = $anyPhotos || $listing->hasPhotos();

                $markup->addRow(InlineKeyboardButton::make(
                    $this->rentalService->buttonLabel($listing, $own),
                    callback_data: 'rent:view:' . $listing->getId(),
                ));
            }

            // Written as sentences, not as a "📌 — ..." glossary: a dash after an emoji
            // reads as part of the button label above it rather than as an explanation
            // of the badge.
            $legend = [];

            if ($mine) {
                $legend[] = '<i>Ваше оголошення позначене 📌.</i>';
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
                    $nav[] = InlineKeyboardButton::make('⬅️', callback_data: 'rent:page:' . ($page - 1));
                }

                $nav[] = InlineKeyboardButton::make(
                    sprintf('%d/%d', $page, $pages),
                    callback_data: 'rent:page:' . $page,
                );

                if ($page < $pages) {
                    $nav[] = InlineKeyboardButton::make('➡️', callback_data: 'rent:page:' . ($page + 1));
                }

                $markup->addRow(...$nav);
            }
        }

        if (!$mine && $account && $this->rentalService->canPublish($account)) {
            $markup->addRow(
                InlineKeyboardButton::make('➕ Здаю квартиру', callback_data: RentalPublish::START_CALLBACK),
            );
        }

        $markup->addRow(StartCommand::homeButton());

        $this->respond($bot, $edit, implode("\n", $lines), $markup);
    }

    /**
     * One listing, in full: everything that used to be dumped into the index.
     *
     * The owner's own card carries the management controls instead of a "write to me"
     * button — they cannot be interested in their own flat.
     */
    private function renderCard(Nutgram $bot, int $listingId, int $index = 0): void
    {
        $listing = $this->liveListing($listingId);

        if (!$listing) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Це оголошення вже неактуальне.');
            return;
        }

        $index = $this->normaliseIndex($listing, $index);

        $lines = [$this->rentalService->describe($listing)];
        $markup = InlineKeyboardMarkup::make();

        $this->addPhotoNav($markup, $listing, $index);
        $this->addCardControls($bot, $markup, $listing, $lines);

        $text = implode("\n", $lines);

        if ($listing->hasPhotos() && $this->sendPhotoCard($bot, $listing, $index, $text, $markup)) {
            return;
        }

        $this->respond($bot, edit: true, text: $text, markup: $markup);
    }

    /**
     * Everything under the picture except the arrows — contact or owner controls, then
     * the way back. Shared with showPhoto() so leafing through photos cannot quietly
     * produce a card with a different keyboard than the one you opened.
     *
     * @param string[] $lines caption lines, appended to for the owner's contact hint
     */
    private function addCardControls(
        Nutgram $bot,
        InlineKeyboardMarkup $markup,
        RentalListing $listing,
        array &$lines,
    ): void {
        if ($this->ownsListing($bot, $listing)) {
            $lines[] = '';
            $lines[] = $this->rentalService->contactHint($listing);

            // Says what it does, now that the arrows above are how you look at the photos:
            // this one opens the upload page, where they are added and deleted.
            $photoCount = count($listing->getPhotos());
            $markup->addRow(InlineKeyboardButton::make(
                $photoCount === 0
                    ? '📷 Додати фото'
                    : sprintf('📷 Керувати фото (%d/%d)', $photoCount, RentalListing::PHOTOS_MAX),
                callback_data: 'rent:photos:' . $listing->getId(),
            ));
            $markup->addRow(
                InlineKeyboardButton::make('✏️ Змінити', callback_data: RentalPublish::START_CALLBACK),
                InlineKeyboardButton::make('🚫 Зняти', callback_data: 'rent:remove:' . $listing->getId()),
            );
        } else {
            $markup->addRow($this->rentalService->contactButton($listing));
        }

        // Back always lands on page 1: the card does not know which page it was opened
        // from, and with a house this size a second page is rare.
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
     * One picture at a time — a caption holds one. The others are the ⬅️/➡️ arrows
     * (addPhotoNav), which swap this same message's media in place.
     *
     * @return bool false when the picture could not be sent, so the caller falls back to
     *              the plain text card rather than showing the resident nothing.
     */
    private function sendPhotoCard(
        Nutgram $bot,
        RentalListing $listing,
        int $index,
        string $caption,
        InlineKeyboardMarkup $markup,
    ): bool {
        $abs = $this->photoPath($listing, $index);

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
            // Falling back silently is how a wrong InputFile import shipped unnoticed:
            // the card kept rendering as text and nothing said why. Log, then degrade.
            $this->logger->error('rental photo card failed, falling back to text', [
                'listing_id' => $listing->getId(),
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
     * ⬅️ 2/3 ➡️ under the card.
     *
     * A separate message per photo (the first shape this took) buried the card under
     * pictures: three taps meant three messages to scroll past, and the card with the
     * price and the contact button ended up somewhere above them. Arrows keep the whole
     * listing in exactly one message — the picture is swapped in place with
     * editMessageMedia, which is the one edit a photo message accepts.
     *
     * Skipped for a single photo: there is nothing to leaf through.
     */
    private function addPhotoNav(InlineKeyboardMarkup $markup, RentalListing $listing, int $index): void
    {
        $total = count($listing->getPhotos());

        if ($total < 2) {
            return;
        }

        // Wrapping around beats greying out the end arrows: with two or three pictures the
        // owner is flicking back and forth, not navigating a catalogue.
        $prev = ($index - 1 + $total) % $total;
        $next = ($index + 1) % $total;

        $markup->addRow(
            InlineKeyboardButton::make('⬅️', callback_data: sprintf('rent:pic:%d:%d', $listing->getId(), $prev)),
            InlineKeyboardButton::make(
                sprintf('🖼 %d/%d', $index + 1, $total),
                callback_data: self::NOOP_CALLBACK,
            ),
            InlineKeyboardButton::make('➡️', callback_data: sprintf('rent:pic:%d:%d', $listing->getId(), $next)),
        );
    }

    /**
     * An arrow was tapped: put photo #$index into the card that is already on screen.
     *
     * editMessageMedia rather than a fresh message — that is what makes this a carousel
     * and not another picture in the chat. It fails on a card that was rendered as text
     * (no photos, or the file had vanished), so that case falls back to a full re-render.
     */
    private function showPhoto(Nutgram $bot, int $listingId, int $index): void
    {
        $listing = $this->liveListing($listingId);

        if (!$listing) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Це оголошення вже неактуальне.');
            return;
        }

        $index = $this->normaliseIndex($listing, $index);
        $abs = $this->photoPath($listing, $index);
        $stream = $abs ? @fopen($abs, 'rb') : false;

        if ($stream === false) {
            // The file is gone but the card is fine: re-render rather than leave the
            // resident tapping an arrow that does nothing.
            $bot->answerCallbackQuery(text: '⚠️ Це фото більше недоступне.');
            $this->renderCard($bot, $listingId);
            return;
        }

        $lines = [$this->rentalService->describe($listing)];
        $markup = InlineKeyboardMarkup::make();

        $this->addPhotoNav($markup, $listing, $index);
        $this->addCardControls($bot, $markup, $listing, $lines);

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
            $this->logger->warning('rental photo swap failed, re-rendering the card', [
                'listing_id' => $listing->getId(),
                'index' => $index,
                'error' => $e->getMessage(),
            ]);

            $this->renderCard($bot, $listingId, $index);
        }
    }

    /** Photo index, wrapped into range — a listing can lose a photo while a card is open. */
    private function normaliseIndex(RentalListing $listing, int $index): int
    {
        $total = count($listing->getPhotos());

        if ($total < 1) {
            return 0;
        }

        return (($index % $total) + $total) % $total;
    }

    /** Readable absolute path of photo #$index, or null. */
    private function photoPath(RentalListing $listing, int $index): ?string
    {
        $path = $listing->getPhotos()[$index] ?? null;
        $abs = $path ? $this->photoService->absolutePath($path) : null;

        return $abs && is_readable($abs) ? $abs : null;
    }

    /**
     * Photos are uploaded on the web, not sent to the bot — see RentalPhotoService for
     * why. All this does is mint a fresh link and hand it over.
     */
    private function photoLink(Nutgram $bot, int $listingId): void
    {
        $listing = $this->liveListing($listingId);

        if (!$listing || !$this->ownsListing($bot, $listing)) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Оголошення не знайдено.');
            return;
        }

        $token = $this->photoService->issueToken($listing);

        $url = $this->urlGenerator->generate(
            'rental_photo_page',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $this->respond(
            $bot,
            edit: true,
            text: "📷 <b>Фото квартири</b>\n\n"
                . 'Відкрийте сторінку і виберіть до ' . RentalListing::PHOTOS_MAX
                . " фото з галереї телефону — вони одразу з'являться в оголошенні.\n\n"
                . '<i>Посилання діє ' . RentalListing::PHOTO_TOKEN_TTL_HOURS . ' години і лише для вашого оголошення. '
                . "Фото альтанки сюди не вантажте — їх, як і раніше, надсилайте прямо в бот.</i>",
            // web_app, not a plain url: opened this way the page runs inside Telegram and
            // can call Telegram.WebApp.close() when the owner is done, dropping them back
            // in the chat instead of leaving a browser tab open. A plain link cannot close
            // itself. The page still works if it is opened outside Telegram — it just
            // shows a "back to the bot" link instead of closing.
            markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    '📷 Відкрити сторінку',
                    web_app: WebAppInfo::make($url),
                ))
                ->addRow(InlineKeyboardButton::make('⬅️ До оголошення', callback_data: 'rent:view:' . $listing->getId()))
                ->addRow(StartCommand::homeButton()),
        );
    }

    /**
     * Owner has no @username, so the interested resident can't be handed a t.me link.
     * Push their contact to the owner instead and say plainly what happens next.
     */
    private function contact(Nutgram $bot, int $listingId, bool $sharePhone = false): void
    {
        $listing = $this->liveListing($listingId);
        $user = $this->telegramUserService->getCurrentUser();

        if (!$listing || !$user) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Це оголошення вже неактуальне.');
            return;
        }

        if ($user->getAccount() && $listing->getAccount()->getId() === $user->getAccount()->getId()) {
            $this->renderMenu($bot, edit: true, notice: 'ℹ️ Це ваше власне оголошення.');
            return;
        }

        // Neither side has a @username: relaying a name alone leaves the owner unable to
        // answer, and the old copy sent the interested resident away to reconfigure
        // Telegram. Offer to pass the number instead — but ask first, this is their data.
        $ownPhone = RentalListingService::formatPhone($user->getPhoneNumber());

        if (!$sharePhone && !$user->getUsername() && $ownPhone !== null) {
            $this->askPhoneConsent($bot, $listing, $ownPhone);
            return;
        }

        $delivered = $this->rentalService->relayContact($listing, $user, $sharePhone);

        if (!$delivered) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Не вдалося сповістити власника — він не користується ботом.');
            return;
        }

        if ($sharePhone) {
            $notice = '✅ Власнику передано ваш номер ' . self::esc($ownPhone ?? '') . ' — очікуйте дзвінка.';
        } elseif ($user->getUsername()) {
            $notice = '✅ Власника сповіщено — він напише вам у Telegram.';
        } else {
            $notice = '✅ Власника сповіщено. Щоб він міг вам відповісти, додайте @username у налаштуваннях Telegram.';
        }

        $this->renderMenu($bot, edit: true, notice: $notice);
    }

    /** Their number, their call — shown in full, with a way out that isn't a dead end. */
    private function askPhoneConsent(Nutgram $bot, RentalListing $listing, string $phone): void
    {
        $this->respond(
            $bot,
            edit: true,
            text: "📞 <b>Як власник з вами зв'яжеться?</b>\n\n"
                . 'У вас не налаштований @username, тому написати вам у Telegram він не зможе. '
                . 'Передати йому ваш номер <b>' . self::esc($phone) . "</b>?\n\n"
                . '<i>Номер побачить лише власник цього оголошення, не весь будинок.</i>',
            markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    '📞 Так, передати номер',
                    callback_data: 'rent:phone:' . $listing->getId(),
                ))
                ->addRow(InlineKeyboardButton::make('⬅️ Назад', callback_data: self::MENU_CALLBACK)),
        );
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function extend(Nutgram $bot, int $listingId): void
    {
        $listing = $this->liveListing($listingId);

        if (!$listing || !$this->ownsListing($bot, $listing)) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Оголошення не знайдено.');
            return;
        }

        $this->rentalService->extend($listing);

        $this->renderMenu(
            $bot,
            edit: true,
            notice: '✅ Оголошення продовжено до ' . $listing->getExpiresAt()->format('d.m.Y') . '.'
        );
    }

    private function remove(Nutgram $bot, int $listingId): void
    {
        $listing = $this->liveListing($listingId);

        if (!$listing || !$this->ownsListing($bot, $listing)) {
            $this->renderMenu($bot, edit: true, notice: '⚠️ Оголошення не знайдено.');
            return;
        }

        $this->rentalService->withdraw($listing);

        $this->renderMenu($bot, edit: true, notice: '🚫 Оголошення знято зі списку.');
    }

    private function liveListing(int $listingId): ?RentalListing
    {
        if ($listingId <= 0) {
            return null;
        }

        $listing = $this->listingRepository->find($listingId);

        return $listing && $listing->isActive() ? $listing : null;
    }

    /**
     * Any member of the account may manage its listing — same rule as bookings and
     * ballots, where the account (not the individual) is the unit.
     */
    private function ownsListing(Nutgram $bot, RentalListing $listing): bool
    {
        $account = $this->currentAccount($bot);

        return $account !== null && $account->getId() === $listing->getAccount()->getId();
    }

    /**
     * $edit fails when the message on screen is a photo card — Telegram will not turn a
     * picture back into text. Then the card is deleted and a text message takes its
     * place, so leaving a listing never leaves its photo hanging in the chat above.
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
}
