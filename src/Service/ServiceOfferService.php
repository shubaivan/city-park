<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\ServiceOffer;
use App\Entity\TelegramUser;
use App\Repository\ServiceOfferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Publishing and lifecycle of "🛠 Послуги" — the house's own list of who does
 * what: плиточник з 23-го, електрик, манікюр, репетитор з англійської.
 *
 * It exists for the same reason the complaints register does: the answer already lives in
 * the residents' chat, where it is a message that scrolls away. «Хто робив вам ремонт?» is
 * asked in that chat every few weeks and answered by whoever happens to be reading it that
 * hour, so the same three names circulate and everybody else's work is invisible.
 *
 * Kept out of the Nutgram handlers because three entry points share it: the bot
 * conversation that publishes, the service:expire cron that prompts and closes, and the
 * admin table that takes an offer down.
 */
class ServiceOfferService
{
    /** Where work photos live. Separate tree from rental and pavilion photos. */
    public const PHOTO_DIR = 'uploads/service-photos';

    public function __construct(
        private ServiceOfferRepository $offers,
        private EntityManagerInterface $em,
        private Nutgram $bot,
        private LoggerInterface $logger,
        private ImageStore $images,
        private ResidentChatService $residentChat,
    ) {}

    public static function now(): \DateTime
    {
        return SchedulePavilionService::createNewDate();
    }

    /**
     * Who may offer a service.
     *
     * Anyone the ОСББ recognises — and deliberately nothing more than that:
     *
     * - **`is_active` is NOT checked.** A debt or a missed pavilion photo blocks *booking*.
     *   Blocking a debtor from advertising the work they do would be the ОСББ taking away
     *   the very thing that lets them pay, which is neither its business nor in its
     *   interest. Same call as the rental noticeboard and the residents' chat.
     * - **The unit type is NOT checked**, unlike a rental listing. A rental card is written
     *   about a flat, so a комірчина cannot fill one in; a person with a parking space and
     *   no flat can still lay tiles. What is needed from the Account is the address on the
     *   card, and every kind of object has one.
     */
    public function canPublish(?Account $account): bool
    {
        return $account instanceof Account;
    }

    public function activeForAuthor(?TelegramUser $author): ?ServiceOffer
    {
        return $author ? $this->offers->findActiveForAuthor($author, self::now()) : null;
    }

    /** @return ServiceOffer[] */
    public function activeOffers(): array
    {
        return $this->offers->findActive(self::now());
    }

    public function countActive(): int
    {
        return $this->offers->countActive(self::now());
    }

    /**
     * Publish (or replace) this person's offer.
     *
     * Replacing rather than rejecting is the edit path: somebody who wants to change their
     * price publishes again instead of hunting for an edit button. Same shape as the
     * rental board, where it has worked since August.
     */
    public function publish(
        Account $account,
        ?TelegramUser $author,
        string $title,
        ?string $priceNote,
        ?string $description,
        bool $showPhone = false,
    ): ServiceOffer {
        $now = self::now();

        $existing = $this->activeForAuthor($author);
        if ($existing) {
            $existing->setStatus(ServiceOffer::STATUS_REMOVED);
            $existing->setClosedAt($now);
            $this->purgePhotos($existing);
        }

        $phone = $showPhone ? RentalListingService::formatPhone($author?->getPhoneNumber()) : null;

        $offer = (new ServiceOffer())
            ->setAccount($account)
            ->setAuthor($author)
            ->setTitle($title)
            ->setPriceNote($priceNote)
            ->setDescription($description)
            ->setShowPhone($phone !== null)
            ->setContactPhone($phone)
            ->setExpiresAt((clone $now)->modify('+' . ServiceOffer::LIFETIME_DAYS . ' days'));

        $this->em->persist($offer);
        $this->em->flush();

        if ($existing) {
            $this->unannounce($existing);
        }

        $this->announce($offer);

        $this->logger->info('service offer published', [
            'offer_id' => $offer->getId(),
            'account_id' => $account->getId(),
            'replaced' => $existing?->getId(),
        ]);

        return $offer;
    }

    /** The author took it down. */
    public function withdraw(ServiceOffer $offer): void
    {
        $offer->setStatus(ServiceOffer::STATUS_REMOVED);
        $offer->setClosedAt(self::now());
        $this->unannounce($offer);
        $this->purgePhotos($offer);
        $this->em->flush();
    }

    /**
     * An admin took it down from /admin/services.
     *
     * Photos are kept here, unlike a withdrawal — on a take-down the picture is usually
     * the reason for it, and deleting the evidence with the row would leave the accountant
     * explaining a decision she can no longer show. Same rule as the rental board.
     */
    public function block(ServiceOffer $offer, string $adminLogin): void
    {
        $offer->setStatus(ServiceOffer::STATUS_BLOCKED);
        $offer->setClosedAt(self::now());
        $offer->setClosedBy($adminLogin);
        $this->unannounce($offer);
        $this->em->flush();
    }

    /** Author confirmed it is still current — another full lifetime, prompt re-armed. */
    public function extend(ServiceOffer $offer): void
    {
        $offer->setExpiresAt(
            (clone self::now())->modify('+' . ServiceOffer::LIFETIME_DAYS . ' days')
        );
        $offer->setRenewPromptSentAt(null);
        $this->em->flush();
    }

    /**
     * The card, HTML parse mode.
     *
     * The trade first and in bold: it is what the reader came for. The flat is under it,
     * not as an address to visit but as the reason to trust the advert at all — this is a
     * neighbour, not a card wedged into the lift door.
     */
    public function describe(ServiceOffer $offer): string
    {
        $lines = [
            '🛠 <b>' . self::esc($offer->getTitle()) . '</b>',
            '🏠 <i>' . self::place($offer->getAccount()) . '</i>',
            '💰 ' . self::esc($offer->priceLabel()),
        ];

        if ($offer->getDescription()) {
            $lines[] = '';
            $lines[] = self::esc($offer->getDescription());
        }

        if ($phone = $offer->publicPhone()) {
            $lines[] = '📞 ' . self::esc($phone);
        }

        // getCreatedAt() is null until the row is flushed — see the trait, which returns
        // null there on purpose so that rendering an unsaved entity is an empty cell and
        // not a fatal. The publish preview does exactly that.
        $published = $offer->getCreatedAt();

        $lines[] = '';
        $lines[] = '<i>' . ($published ? 'Опубліковано ' . $published->format('d.m.Y') . ' · ' : '')
            . 'діє до ' . $offer->getExpiresAt()->format('d.m.Y') . '</i>';

        return implode("\n", $lines);
    }

    /**
     * One-line label for the index.
     *
     * The trade leads, because that is the whole question the reader is scanning for —
     * the price and the flat only matter once they have found a плиточник at all.
     * Telegram truncates long button captions, which is also why ServiceOffer::TITLE_MAX
     * is short.
     */
    public function buttonLabel(ServiceOffer $offer, bool $own = false): string
    {
        $parts = array_filter([
            $offer->getTitle(),
            $offer->priceLabel(),
        ]);

        return ($own ? '📌 ' : '')
            . ($offer->hasPhotos() ? '📷 ' : '')
            . implode(' · ', $parts);
    }

    /**
     * The "write to them" button.
     *
     * A t.me link when the author has a @username — one tap into the chat. Without one
     * there is no link Telegram will reliably open, so the bot relays instead: the
     * interested neighbour's contact is pushed to the author, who answers first. Only
     * ~48% of rows have a username, so the relay is the common path, not the exception.
     */
    public function contactButton(ServiceOffer $offer): InlineKeyboardButton
    {
        $username = $offer->getAuthor()?->getUsername();

        if ($username) {
            return InlineKeyboardButton::make('✍️ Написати', url: 'https://t.me/' . $username);
        }

        return InlineKeyboardButton::make(
            '✍️ Зв\'язатися',
            callback_data: 'svc:contact:' . $offer->getId(),
        );
    }

    /**
     * The author never sees their own contact button — their card carries the management
     * controls instead — so spell out how neighbours will actually reach them. Without
     * this they see a card with no visible way to be contacted and assume it is useless.
     */
    public function contactHint(ServiceOffer $offer): string
    {
        $username = $offer->getAuthor()?->getUsername();
        $phone = $offer->publicPhone();

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
            . 'Щоб замовлення знаходили вас швидше, опублікуйте оголошення ще раз і '
            . 'дозвольте показати номер.';
    }

    /**
     * Push an interested neighbour's contact to the author.
     *
     * @return bool false when nobody on the author's side has a chat_id to receive it.
     */
    public function relayContact(ServiceOffer $offer, TelegramUser $interested, bool $sharePhone = false): bool
    {
        $name = trim(implode(' ', array_filter([$interested->getFirstName(), $interested->getLastName()])));

        $who = $name !== '' ? self::esc($name) : 'Мешканець';

        if ($interested->getAccount()?->getApartmentNumber()) {
            $who .= ' (' . self::place($interested->getAccount()) . ')';
        }

        if ($interested->getUsername()) {
            $who .= ' · @' . self::esc($interested->getUsername());
        }

        $phone = $sharePhone ? RentalListingService::formatPhone($interested->getPhoneNumber()) : null;
        if ($phone) {
            $who .= "\n📞 " . self::esc($phone);
        }

        if ($interested->getUsername()) {
            $how = 'Напишіть у Telegram, якщо ви ще берете замовлення.';
        } elseif ($phone) {
            $how = 'Зателефонуйте, якщо ви ще берете замовлення.';
        } else {
            $how = 'У цієї людини не налаштований @username, тож написати їй у Telegram поки що не вийде — '
                . 'ми попросили її додати username і звернутися ще раз.';
        }

        $text = "🛠 <b>Цікавляться вашою послугою</b>\n\n"
            . '<i>' . self::esc($offer->getTitle()) . "</i>\n\n"
            . $who . "\n\n"
            . $how;

        $recipients = $this->recipients($offer);
        if (!$recipients) {
            return false;
        }

        $sent = false;
        foreach ($recipients as $chatId) {
            try {
                $this->bot->sendMessage(text: $text, chat_id: $chatId, parse_mode: ParseMode::HTML);
                $sent = true;
            } catch (\Throwable $t) {
                $this->logger->warning('service: contact relay failed', [
                    'offer_id' => $offer->getId(),
                    'error' => $t->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Ask authors whose offer is about to lapse whether they still take work.
     *
     * @return int prompts sent
     */
    public function sendDueRenewPrompts(): int
    {
        $now = self::now();
        $soon = (clone $now)->modify('+' . ServiceOffer::RENEW_PROMPT_BEFORE_DAYS . ' days');

        $sent = 0;

        foreach ($this->offers->findDueRenewPrompt($now, $soon) as $offer) {
            $text = "🛠 <b>Ваше оголошення про послугу скоро зникне зі списку</b>\n\n"
                . $this->describe($offer) . "\n\n"
                . 'Ви ще берете замовлення?';

            $markup = InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('✅ Так, продовжити', callback_data: 'svc:extend:' . $offer->getId()),
                InlineKeyboardButton::make('🚫 Зняти', callback_data: 'svc:remove:' . $offer->getId()),
            );

            $delivered = false;
            foreach ($this->recipients($offer) as $chatId) {
                try {
                    $this->bot->sendMessage(
                        text: $text,
                        chat_id: $chatId,
                        parse_mode: ParseMode::HTML,
                        reply_markup: $markup,
                    );
                    $delivered = true;
                } catch (\Throwable $t) {
                    $this->logger->warning('service: renew prompt failed', [
                        'offer_id' => $offer->getId(),
                        'error' => $t->getMessage(),
                    ]);
                }
            }

            // Stamped even when nothing could be delivered, otherwise somebody who blocked
            // the bot would be retried every day until the offer expires anyway.
            $offer->setRenewPromptSentAt($now);
            $sent += $delivered ? 1 : 0;
        }

        $this->em->flush();

        return $sent;
    }

    /**
     * Close offers whose lifetime ran out.
     *
     * @return int offers closed
     */
    public function closeExpired(): int
    {
        $now = self::now();
        $closed = 0;

        foreach ($this->offers->findExpired($now) as $offer) {
            $offer->setStatus(ServiceOffer::STATUS_EXPIRED);
            $offer->setClosedAt($now);
            $this->purgePhotos($offer);
            $this->unannounce($offer);
            $closed++;

            foreach ($this->recipients($offer) as $chatId) {
                try {
                    $this->bot->sendMessage(
                        text: '🛠 Ваше оголошення «' . self::esc($offer->getTitle())
                            . '» знято зі списку через ' . ServiceOffer::LIFETIME_DAYS . " днів.\n"
                            . 'Якщо ви ще берете замовлення — опублікуйте його знову: /services',
                        chat_id: $chatId,
                        parse_mode: ParseMode::HTML,
                    );
                } catch (\Throwable $t) {
                    $this->logger->warning('service: expiry notice failed', [
                        'offer_id' => $offer->getId(),
                        'error' => $t->getMessage(),
                    ]);
                }
            }
        }

        $this->em->flush();

        return $closed;
    }

    ##########
    # Photos — web only, never through the bot. See ServiceOffer::$photo_token.
    ##########

    /**
     * Issue (or refresh) the upload link.
     *
     * Regenerated on every request so a link forwarded to somebody yesterday stops working
     * as soon as a fresh one is asked for.
     */
    public function issueToken(ServiceOffer $offer): string
    {
        $token = bin2hex(random_bytes(16));

        $offer->setPhotoToken($token);
        $offer->setPhotoTokenExpiresAt(
            (clone self::now())->modify('+' . ServiceOffer::PHOTO_TOKEN_TTL_HOURS . ' hours')
        );

        $this->em->flush();

        return $token;
    }

    public function findByToken(?string $token): ?ServiceOffer
    {
        if (!$token || !preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }

        $offer = $this->offers->findOneByToken($token);

        if (!$offer || !$offer->isActive()) {
            return null;
        }

        return $offer->isPhotoTokenValid(self::now()) ? $offer : null;
    }

    /** @return string|null the public path, or null with $error set */
    public function storePhoto(ServiceOffer $offer, UploadedFile $file, ?string &$error = null): ?string
    {
        if (count($offer->getPhotos()) >= ServiceOffer::PHOTOS_MAX) {
            $error = 'Більше ' . ServiceOffer::PHOTOS_MAX . ' фото не можна.';

            return null;
        }

        $path = $this->images->store($file, self::PHOTO_DIR, $error);

        if ($path === null) {
            return null;
        }

        $offer->setPhotos([...$offer->getPhotos(), $path]);
        $this->em->flush();

        $this->logger->info('service photo stored', [
            'offer_id' => $offer->getId(),
            'path' => $path,
        ]);

        return $path;
    }

    public function removePhoto(ServiceOffer $offer, string $publicPath): void
    {
        $offer->setPhotos(array_values(array_filter(
            $offer->getPhotos(),
            static fn (string $p): bool => $p !== $publicPath,
        )));

        $this->em->flush();
        $this->images->delete($publicPath, self::PHOTO_DIR);
    }

    /** "Готово" on the page: the link has served its purpose, retire it. */
    public function burnToken(ServiceOffer $offer): void
    {
        $offer->setPhotoToken(null);
        $offer->setPhotoTokenExpiresAt(null);
        $this->em->flush();
    }

    /** Files must not outlive the offer they belonged to. */
    public function purgePhotos(ServiceOffer $offer): void
    {
        foreach ($offer->getPhotos() as $path) {
            $this->images->delete($path, self::PHOTO_DIR);
        }

        $offer->setPhotos([]);
        $offer->setPhotoToken(null);
        $offer->setPhotoTokenExpiresAt(null);
    }

    public function absolutePath(?string $publicPath): ?string
    {
        return $this->images->absolutePath($publicPath, self::PHOTO_DIR);
    }

    /**
     * Send the author their updated card after they finish on the photo page.
     *
     * The Web App closes itself and they land back in the chat — this is what they land
     * on. The message that opened the page still shows the old count, and editing it from
     * an HTTP request would mean carrying its message_id around for no real gain.
     */
    public function notifyPhotosUpdated(ServiceOffer $offer): void
    {
        $recipients = $this->recipients($offer);

        if (!$recipients) {
            return;
        }

        $caption = "📷 <b>Фото оновлено</b>\n\n" . $this->describe($offer);

        $markup = InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
            '🛠 Моє оголошення',
            callback_data: 'svc:view:' . $offer->getId(),
        ));

        $cover = $offer->coverPhoto();
        $abs = $cover ? $this->absolutePath($cover) : null;

        foreach ($recipients as $chatId) {
            try {
                $stream = $abs && is_readable($abs) ? @fopen($abs, 'rb') : false;

                if ($stream !== false) {
                    $this->bot->sendPhoto(
                        photo: InputFile::make($stream, basename((string)$abs)),
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
                $this->logger->error('service photo notice failed', [
                    'offer_id' => $offer->getId(),
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    ##########
    # The residents' chat
    ##########

    /**
     * Put the offer in the residents' chat, in its own topic.
     *
     * **Only when that topic is configured** — and this is the one place where the rule
     * differs from the rental board. A rental listing posts to General when no topic is
     * set, because that is where «здам квартиру» was always written and the bot merely
     * took it over. Services never had that: this board exists to *reduce* the «хто робив
     * вам ремонт?» traffic in the house chat, and dropping every advert into General
     * unasked would add exactly the noise it is meant to remove. With no topic the offers
     * live in the bot, which is enough.
     *
     * Never fatal: an unreachable chat must not stop somebody publishing.
     */
    public function announce(ServiceOffer $offer): void
    {
        $topic = $this->residentChat->topic(ResidentChatService::TOPIC_SERVICES);

        if (!$this->residentChat->isConfigured() || $topic === null) {
            return;
        }

        try {
            $message = $this->bot->sendMessage(
                text: $this->chatPost($offer),
                chat_id: (int)$this->residentChat->chatId(),
                message_thread_id: $topic,
                parse_mode: ParseMode::HTML,
                disable_notification: true,
            );

            $offer->setChatMessageId($message?->message_id);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('service chat announcement failed', [
                'offer_id' => $offer->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Take the post down with the offer — see ServiceOffer::$chat_message_id. */
    public function unannounce(ServiceOffer $offer): void
    {
        $messageId = $offer->getChatMessageId();

        if ($messageId === null || !$this->residentChat->isConfigured()) {
            return;
        }

        try {
            $this->bot->deleteMessage((int)$this->residentChat->chatId(), $messageId);
        } catch (\Throwable $e) {
            // Telegram refuses to delete anything older than 48 hours, and somebody may
            // have removed it by hand. Either way the offer is closed; a stale post is not
            // worth a failed take-down.
            $this->logger->info('service chat post not deleted', [
                'offer_id' => $offer->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        $offer->setChatMessageId(null);
    }

    /**
     * The chat post. Short on purpose, and **never a phone number**: consent was given for
     * the card in the bot, which linked residents open, not for a message the whole house
     * reads and can forward anywhere. Same call as the rental board.
     */
    public function chatPost(ServiceOffer $offer): string
    {
        $lines = [
            '🛠 <b>' . self::esc($offer->getTitle()) . '</b> · ' . self::place($offer->getAccount()),
            '💰 ' . self::esc($offer->priceLabel()),
        ];

        if ($description = $offer->getDescription()) {
            $lines[] = self::esc(mb_strimwidth($description, 0, 220, '…'));
        }

        $lines[] = '';
        $lines[] = '<i>Деталі, фото і контакт — у боті, кнопка «🛠 Послуги».</i>';

        return implode("\n", $lines);
    }

    /**
     * Chat ids to notify about an offer: its author, and every other member of the account
     * as a fallback so an offer published from a phone that has since been wiped still
     * reaches the household.
     *
     * @return string[]
     */
    private function recipients(ServiceOffer $offer): array
    {
        $ids = [];

        $authorChat = $offer->getAuthor()?->getChatId();
        if ($authorChat) {
            $ids[] = $authorChat;
        }

        if (!$ids) {
            foreach ($offer->getAccount()->getUsers() as $user) {
                if ($user->getChatId()) {
                    $ids[] = $user->getChatId();
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * "буд. 21, кв. 45" — never the unit on its own.
     *
     * Five buildings on one street with repeating apartment numbers, so «кв. 76» names two
     * different households: a reader cannot tell whose neighbour this is, and an author
     * receiving «Цікавляться (кв. 45)» cannot tell who wrote. Delegated to the one
     * implementation rather than written again here — the rental board learned this on
     * 03.09.2026 and there is no second version of the rule to keep in step.
     */
    public static function place(?Account $account): string
    {
        return RentalListingService::place($account);
    }

    /** Unescaped — for inline button captions, which are plain text, not HTML. */
    public static function placePlain(?Account $account, bool $short = false): string
    {
        return RentalListingService::placePlain($account, $short);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
