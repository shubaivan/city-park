<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\RentalListing;
use App\Entity\TelegramUser;
use App\Repository\RentalListingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

/**
 * Publishing and lifecycle of "здається квартира" listings.
 *
 * Kept out of the Nutgram handlers because three entry points share it: the bot
 * conversation that publishes, the rental:expire cron that prompts/closes, and the
 * admin table that takes a listing down.
 */
class RentalListingService
{
    public function __construct(
        private RentalListingRepository $listingRepository,
        private EntityManagerInterface $em,
        private Nutgram $bot,
        private LoggerInterface $logger,
        private RentalPhotoService $photoService,
    ) {}

    public static function now(): \DateTime
    {
        return SchedulePavilionService::createNewDate();
    }

    /**
     * Who may offer an apartment for rent.
     *
     * Apartments only in this version: parking places and storage units are rented out
     * too, but their listing line ("кв. 45 · 2-кімн. · 58 м²") makes no sense for them
     * and the residents asked for flats. Storage is excluded by canBookPavilion() logic
     * anyway; parking is left out deliberately.
     *
     * Deliberately does NOT check is_active: a debt or a missed pavilion photo blocks
     * booking, not the right to rent out your own property. Mixing the two would turn a
     * housekeeping sanction into a property restriction the ОСББ can't justify.
     */
    public function canPublish(Account $account): bool
    {
        return !$account->isStorage() && !$account->isParking();
    }

    public function activeForAccount(Account $account): ?RentalListing
    {
        return $this->listingRepository->findActiveForAccount($account, self::now());
    }

    /**
     * @return RentalListing[]
     */
    public function activeListings(): array
    {
        return $this->listingRepository->findActive(self::now());
    }

    /**
     * Publish (or replace) an account's listing.
     *
     * Replacing rather than rejecting keeps the bot flow forgiving: an owner who wants to
     * change the price just publishes again instead of hunting for an edit button.
     */
    public function publish(
        Account $account,
        ?TelegramUser $author,
        ?int $rooms,
        ?int $price,
        ?string $description,
        bool $showPhone = false,
    ): RentalListing {
        $now = self::now();

        $existing = $this->listingRepository->findActiveForAccount($account, $now);
        if ($existing) {
            $existing->setStatus(RentalListing::STATUS_REMOVED);
            $existing->setClosedAt($now);
            $this->photoService->purge($existing);
        }

        $phone = $showPhone ? self::formatPhone($author?->getPhoneNumber()) : null;

        $listing = (new RentalListing())
            ->setAccount($account)
            ->setAuthor($author)
            ->setRooms($rooms)
            ->setPrice($price)
            ->setDescription($description)
            ->setShowPhone($phone !== null)
            ->setContactPhone($phone)
            ->setExpiresAt((clone $now)->modify('+' . RentalListing::LIFETIME_DAYS . ' days'));

        $this->em->persist($listing);
        $this->em->flush();

        $this->logger->info('rental listing published', [
            'listing_id' => $listing->getId(),
            'account_id' => $account->getId(),
            'replaced' => $existing?->getId(),
        ]);

        return $listing;
    }

    /** Owner took it down ("вже здав"). */
    public function withdraw(RentalListing $listing): void
    {
        $listing->setStatus(RentalListing::STATUS_REMOVED);
        $listing->setClosedAt(self::now());
        // Files must not outlive the listing they belonged to. Admin take-downs are the
        // exception below: there the photo is often the reason it was taken down.
        $this->photoService->purge($listing);
        $this->em->flush();
    }

    /** Admin took it down from /admin/rentals. */
    public function block(RentalListing $listing, string $adminLogin): void
    {
        $listing->setStatus(RentalListing::STATUS_BLOCKED);
        $listing->setClosedAt(self::now());
        $listing->setClosedBy($adminLogin);
        $this->em->flush();
    }

    /** Owner confirmed it is still current — another full lifetime, prompt re-armed. */
    public function extend(RentalListing $listing): void
    {
        $now = self::now();
        $listing->setExpiresAt((clone $now)->modify('+' . RentalListing::LIFETIME_DAYS . ' days'));
        $listing->setRenewPromptSentAt(null);
        $this->em->flush();
    }

    /**
     * One-line-per-fact rendering of a listing, HTML parse mode.
     *
     * The особовий рахунок is never shown — the whole house reads this list; the
     * apartment number is what a would-be tenant actually needs.
     */
    public function describe(RentalListing $listing): string
    {
        $account = $listing->getAccount();

        $head = array_filter([
            'кв. ' . self::esc((string)$account->getApartmentNumber()),
            $listing->roomsLabel(),
            $account->getArea() ? rtrim(rtrim(number_format((float)$account->getArea(), 1, ',', ' '), '0'), ',') . ' м²' : null,
        ]);

        $lines = [
            '🏠 <b>' . implode(' · ', $head) . '</b>',
            '💰 ' . self::esc($listing->priceLabel()),
        ];

        if ($listing->getDescription()) {
            $lines[] = self::esc($listing->getDescription());
        }

        if ($phone = $listing->publicPhone()) {
            $lines[] = '📞 ' . self::esc($phone);
        }

        $lines[] = '<i>Опубліковано ' . $listing->getCreatedAt()->format('d.m.Y')
            . ' · діє до ' . $listing->getExpiresAt()->format('d.m.Y') . '</i>';

        return implode("\n", $lines);
    }

    /**
     * One-line label for the index: everything needed to decide whether to open the card.
     * Telegram truncates long button captions, so keep it to apartment / rooms / price.
     */
    public function buttonLabel(RentalListing $listing, bool $own = false): string
    {
        $parts = array_filter([
            'кв. ' . $listing->getAccount()->getApartmentNumber(),
            $listing->roomsLabel(),
            $listing->priceLabel(),
        ]);

        return ($own ? '📌 ' : '') . ($listing->hasPhotos() ? '📷 ' : '') . implode(' · ', $parts);
    }

    /**
     * The "write to the owner" button.
     *
     * A t.me link when the author has a @username — one tap straight into the chat.
     * Without a username there is no link Telegram will reliably open, so the bot
     * relays instead: the interested resident's contact is pushed to the owner, who
     * decides whether to answer. Phone numbers are never printed in the public list.
     */
    public function contactButton(RentalListing $listing): InlineKeyboardButton
    {
        $username = $listing->getAuthor()?->getUsername();

        if ($username) {
            return InlineKeyboardButton::make(
                '✍️ Написати (кв. ' . $listing->getAccount()->getApartmentNumber() . ')',
                url: 'https://t.me/' . $username,
            );
        }

        return InlineKeyboardButton::make(
            '✍️ Хочу орендувати (кв. ' . $listing->getAccount()->getApartmentNumber() . ')',
            callback_data: 'rent:contact:' . $listing->getId(),
        );
    }

    /**
     * The owner never sees their own contact button — their listing carries edit/remove
     * controls instead — so spell out how neighbours will actually reach them. Without
     * this the author sees a card with no visible way to be contacted and assumes the
     * listing is useless.
     */
    public function contactHint(RentalListing $listing): string
    {
        $username = $listing->getAuthor()?->getUsername();
        $phone = $listing->publicPhone();

        if ($username && $phone) {
            return '👤 Сусіди бачать ваш номер ' . self::esc($phone) . ' і кнопку «✍️ Написати» '
                . '(веде у ваш Telegram @' . self::esc($username) . ').';
        }

        if ($username) {
            return '👤 Сусіди бачать кнопку «✍️ Написати» — вона веде у ваш Telegram (@'
                . self::esc($username) . '). Номер телефону ви показувати не дозволили.';
        }

        if ($phone) {
            return '👤 Сусіди бачать ваш номер ' . self::esc($phone) . ' — за ним і зателефонують. '
                . 'Кнопки «Написати» немає: у вас не налаштований @username.';
        }

        return '👤 У вас не вказаний @username і номер ви показувати не дозволили, тому бот '
            . 'перешле вам контакт того, хто зацікавився, і ви напишете йому першим. '
            . 'Щоб вас знаходили швидше, опублікуйте оголошення ще раз і дозвольте показати номер.';
    }

    /**
     * Push an interested resident's contact to the listing's owner.
     *
     * @return bool false when nobody on the owner's side has a chat_id to receive it.
     */
    public function relayContact(RentalListing $listing, TelegramUser $interested, bool $sharePhone = false): bool
    {
        $name = trim(implode(' ', array_filter([$interested->getFirstName(), $interested->getLastName()])));
        $apartment = $interested->getAccount()?->getApartmentNumber();

        $who = $name !== '' ? self::esc($name) : 'Мешканець';
        if ($apartment) {
            $who .= ' (кв. ' . self::esc((string)$apartment) . ')';
        } else {
            // Reading the list is open to anyone who opens the bot, so the person asking
            // may not be linked to an особовий рахунок. Say so plainly instead of implying
            // a neighbour — the owner decides who they answer.
            $who .= ' (не підтверджений ОСББ)';
        }
        if ($interested->getUsername()) {
            $who .= ' · @' . self::esc($interested->getUsername());
        }

        $phone = $sharePhone ? self::formatPhone($interested->getPhoneNumber()) : null;
        if ($phone) {
            $who .= "\n📞 " . self::esc($phone);
        }

        if ($interested->getUsername()) {
            $how = 'Напишіть у Telegram, якщо пропозиція ще актуальна.';
        } elseif ($phone) {
            // The dead end this fixes: neither side has a @username, so before we simply
            // told the owner to wait for a stranger to reconfigure Telegram. Both phones
            // are in our database — the missing piece was consent, not data.
            $how = 'Зателефонуйте, якщо пропозиція ще актуальна.';
        } else {
            $how = 'У цієї людини не налаштований @username, тож написати їй у Telegram поки що не вийде — '
                . 'ми попросили її додати username і звернутися ще раз.';
        }

        $text = "🔑 <b>Цікавляться вашим оголошенням про оренду</b>\n\n"
            . $who . "\n\n"
            . $how;

        $recipients = $this->recipients($listing);
        if (!$recipients) {
            return false;
        }

        $sent = false;
        foreach ($recipients as $chatId) {
            try {
                $this->bot->sendMessage(text: $text, chat_id: $chatId, parse_mode: ParseMode::HTML);
                $sent = true;
            } catch (\Throwable $t) {
                $this->logger->warning('rental: contact relay failed', [
                    'listing_id' => $listing->getId(),
                    'error' => $t->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Ask owners whose listing is about to lapse whether it is still current.
     *
     * @return int prompts sent
     */
    public function sendDueRenewPrompts(): int
    {
        $now = self::now();
        $soon = (clone $now)->modify('+' . RentalListing::RENEW_PROMPT_BEFORE_DAYS . ' days');

        $sent = 0;
        foreach ($this->listingRepository->findDueRenewPrompt($now, $soon) as $listing) {
            $text = "🔑 <b>Ваше оголошення про оренду скоро зникне зі списку</b>\n\n"
                . $this->describe($listing) . "\n\n"
                . 'Квартира ще здається?';

            $markup = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
                ->addRow(
                    InlineKeyboardButton::make('✅ Так, продовжити', callback_data: 'rent:extend:' . $listing->getId()),
                    InlineKeyboardButton::make('🚫 Зняти', callback_data: 'rent:remove:' . $listing->getId()),
                );

            $delivered = false;
            foreach ($this->recipients($listing) as $chatId) {
                try {
                    $this->bot->sendMessage(
                        text: $text,
                        chat_id: $chatId,
                        parse_mode: ParseMode::HTML,
                        reply_markup: $markup,
                    );
                    $delivered = true;
                } catch (\Throwable $t) {
                    $this->logger->warning('rental: renew prompt failed', [
                        'listing_id' => $listing->getId(),
                        'error' => $t->getMessage(),
                    ]);
                }
            }

            // Stamped even when nothing could be delivered, otherwise an owner who blocked
            // the bot would be retried every single day until the listing expires anyway.
            $listing->setRenewPromptSentAt($now);
            $sent += $delivered ? 1 : 0;
        }

        $this->em->flush();

        return $sent;
    }

    /**
     * Close listings whose lifetime ran out.
     *
     * @return int listings closed
     */
    public function closeExpired(): int
    {
        $now = self::now();
        $closed = 0;

        foreach ($this->listingRepository->findExpired($now) as $listing) {
            $listing->setStatus(RentalListing::STATUS_EXPIRED);
            $this->photoService->purge($listing);
            $listing->setClosedAt($now);
            $closed++;

            foreach ($this->recipients($listing) as $chatId) {
                try {
                    $this->bot->sendMessage(
                        text: "🔑 Ваше оголошення про оренду (кв. "
                            . self::esc((string)$listing->getAccount()->getApartmentNumber())
                            . ") знято зі списку через " . RentalListing::LIFETIME_DAYS . " днів.\n"
                            . 'Якщо квартира ще здається — опублікуйте його знову: /rent',
                        chat_id: $chatId,
                        parse_mode: ParseMode::HTML,
                    );
                } catch (\Throwable $t) {
                    $this->logger->warning('rental: expiry notice failed', [
                        'listing_id' => $listing->getId(),
                        'error' => $t->getMessage(),
                    ]);
                }
            }
        }

        $this->em->flush();

        return $closed;
    }

    /**
     * Chat ids to notify about a listing: its author first, and every other member of the
     * account as a fallback so a listing published from a phone that has since been
     * wiped still reaches the family.
     *
     * @return string[]
     */
    /**
     * Send the owner their updated card after they finish on the photo page.
     *
     * The Web App closes itself, so the resident lands back in the chat — this is what
     * they land on: the listing exactly as neighbours now see it, picture included. The
     * message that opened the page still shows the old count, and editing it from an HTTP
     * request would mean carrying its message_id around for no real gain.
     */
    public function notifyPhotosUpdated(RentalListing $listing): void
    {
        $recipients = $this->recipients($listing);

        if (!$recipients) {
            return;
        }

        $caption = "📷 <b>Фото оновлено</b>\n\n" . $this->describe($listing);

        $markup = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                '🔑 Моє оголошення',
                callback_data: 'rent:view:' . $listing->getId(),
            ));

        $cover = $listing->coverPhoto();
        $abs = $cover ? $this->photoService->absolutePath($cover) : null;

        foreach ($recipients as $chatId) {
            try {
                $stream = $abs && is_readable($abs) ? @fopen($abs, 'rb') : false;

                if ($stream !== false) {
                    $this->bot->sendPhoto(
                        photo: \SergiX44\Nutgram\Telegram\Types\Internal\InputFile::make($stream, basename((string)$abs)),
                        chat_id: $chatId,
                        caption: $caption,
                        parse_mode: ParseMode::HTML,
                        reply_markup: $markup,
                    );
                    continue;
                }

                // All photos removed, or the file vanished — the text card still tells
                // them where they stand.
                $this->bot->sendMessage(
                    text: $caption,
                    chat_id: $chatId,
                    parse_mode: ParseMode::HTML,
                    reply_markup: $markup,
                );
            } catch (\Throwable $e) {
                $this->logger->error('rental photo notice failed', [
                    'listing_id' => $listing->getId(),
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function recipients(RentalListing $listing): array
    {
        $ids = [];

        $authorChat = $listing->getAuthor()?->getChatId();
        if ($authorChat) {
            $ids[] = $authorChat;
        }

        if (!$ids) {
            foreach ($listing->getAccount()->getUsers() as $user) {
                if ($user->getChatId()) {
                    $ids[] = $user->getChatId();
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Phones reach us in whatever shape Telegram or the registry import left them
     * ("+380936583202", "380988755469"), so normalise before showing one to a neighbour.
     * Returns NULL for anything that isn't a plausible Ukrainian number rather than
     * printing a half-number nobody can dial.
     */
    public static function formatPhone(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string)$raw);

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '38' . $digits;
        }

        if (strlen($digits) !== 12 || !str_starts_with($digits, '380')) {
            return null;
        }

        return sprintf(
            '+%s %s %s %s %s',
            substr($digits, 0, 3),
            substr($digits, 3, 2),
            substr($digits, 5, 3),
            substr($digits, 8, 2),
            substr($digits, 10, 2),
        );
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
