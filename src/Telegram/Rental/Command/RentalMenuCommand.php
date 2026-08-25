<?php

namespace App\Telegram\Rental\Command;

use App\Entity\Account;
use App\Entity\RentalListing;
use App\Repository\RentalListingRepository;
use App\Service\RentalListingService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * "🔑 Оренда" — the house's list of apartments currently offered for rent, plus the
 * owner's controls for their own listing. Callbacks:
 *   rental-menu           — render the list
 *   rent:contact:<id>     — relay the caller's contact to an owner without a @username
 *   rent:phone:<id>       — same, with the caller's own number attached (they consented)
 *   rent:extend:<id>      — owner confirms the listing is still current (+30 days)
 *   rent:remove:<id>      — owner takes their listing down
 *
 * Publishing itself lives in the RentalPublish conversation (button "➕ Здаю квартиру").
 */
class RentalMenuCommand
{
    public const MENU_CALLBACK = 'rental-menu';

    /** Above this the list is trimmed — a Telegram message and an inline keyboard both have limits. */
    private const RENDER_LIMIT = 15;

    public function __construct(
        private TelegramUserService $telegramUserService,
        private RentalListingService $rentalService,
        private RentalListingRepository $listingRepository,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->isCallbackQuery() ? ($bot->callbackQuery()->data ?? '') : '';

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

    private function renderMenu(Nutgram $bot, bool $edit, ?string $notice = null): void
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
            $shown = array_slice($listings, 0, self::RENDER_LIMIT);

            foreach ($shown as $listing) {
                $lines[] = $this->rentalService->describe($listing);
                $lines[] = '';

                // Own listing needs controls, not a "write to me" button.
                if ($mine && $listing->getId() === $mine->getId()) {
                    continue;
                }

                $markup->addRow($this->rentalService->contactButton($listing));
            }

            if (count($listings) > self::RENDER_LIMIT) {
                $lines[] = sprintf(
                    '<i>… та ще %d оголошень. Показані найновіші.</i>',
                    count($listings) - self::RENDER_LIMIT
                );
                $lines[] = '';
            }
        }

        if ($mine) {
            $lines[] = '— — —';
            $lines[] = '📌 <b>Ваше оголошення</b> діє до ' . $mine->getExpiresAt()->format('d.m.Y') . '.';
            $lines[] = $this->rentalService->contactHint($mine);
            $markup->addRow(
                InlineKeyboardButton::make('✏️ Змінити', callback_data: RentalPublish::START_CALLBACK),
                InlineKeyboardButton::make('🚫 Зняти', callback_data: 'rent:remove:' . $mine->getId()),
            );
        } elseif ($account && $this->rentalService->canPublish($account)) {
            $markup->addRow(
                InlineKeyboardButton::make('➕ Здаю квартиру', callback_data: RentalPublish::START_CALLBACK),
            );
        }

        $markup->addRow(StartCommand::homeButton());

        $this->respond($bot, $edit, implode("\n", $lines), $markup);
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

    private function respond(Nutgram $bot, bool $edit, string $text, InlineKeyboardMarkup $markup): void
    {
        if ($edit) {
            try {
                $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
                return;
            } catch (\Throwable) {
                // fall through to a fresh message
            }
        }

        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }
}
