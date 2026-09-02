<?php

namespace App\Service;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Chat\ChatJoinRequest;

/**
 * The gate on the residents' Telegram group.
 *
 * The house already has the only verified list of its own residents: Account ↔
 * TelegramUser, built by the accountant against the ОСББ registry. This service does
 * nothing more than let that list decide who gets into the chat — every join goes
 * through a join-request link, so the bot vets the actual joining user_id and a
 * forwarded link buys an outsider nothing.
 *
 * Deliberate rules:
 *
 * - **`is_active` is NOT checked.** A debt or a missed pavilion photo blocks *booking*.
 *   The chat is where the house announces things — including, eventually, that the
 *   person owes money. Cutting a debtor out of the announcements is both petty and
 *   counterproductive. Same call as the rental noticeboard (`RentalListingService`).
 * - **Parking- and storage-only accounts are let in.** They pay ОСББ dues like anyone
 *   else; `isNonResidential()` exists to stop them *booking the pavilion*, which is a
 *   different question from whether they may read the house chat.
 * - **A declined request is not a ban.** Telegram lets the same person ask again after
 *   they link their account, which is exactly the path the refusal text describes.
 */
class ResidentChatService
{
    public function __construct(
        private TelegramUserRepository $telegramUserRepository,
        private TelegramUserService $telegramUserService,
        private LoggerInterface $chatLogger,
        private string $residentChatId = '',
        private string $residentChatInviteLink = '',
    ) {}

    /** The chat exists and the bot knows how to send people to it. */
    public function isConfigured(): bool
    {
        return $this->residentChatId !== '' && $this->residentChatInviteLink !== '';
    }

    public function inviteLink(): string
    {
        return $this->residentChatInviteLink;
    }

    public function chatId(): string
    {
        return $this->residentChatId;
    }

    /**
     * May this Telegram user be let into the chat?
     *
     * The single condition is that the accountant has linked them to an особовий
     * рахунок — directly, or through a phone listed as a conditional owner, which
     * resolveAccount() persists on first match so family members and tenants the owner
     * registered come out verified too.
     */
    public function mayJoin(TelegramUser $user): bool
    {
        return $this->telegramUserService->resolveAccount($user) !== null;
    }

    /**
     * Answer one join request: approve a resident, refuse anyone else with the reason.
     *
     * The refusal has to carry its own explanation. `user_chat_id` is the bot's only
     * way to reach someone it has never spoken to, and Telegram keeps that door open
     * for 24 hours — but only until the request is processed. Declining first and
     * writing afterwards loses the message, so the text goes out before the decline.
     */
    public function handleJoinRequest(Nutgram $bot, ChatJoinRequest $request): void
    {
        // The bot may sit in other chats; only this one is gated.
        if ((string)$request->chat->id !== $this->residentChatId) {
            return;
        }

        $telegramId = (string)$request->from->id;
        $user = $this->telegramUserRepository->getByTelegramId($telegramId);
        $allowed = $user && $this->mayJoin($user);

        $this->chatLogger->info('resident chat join request', [
            'telegram_id' => $telegramId,
            'username' => $request->from->username,
            'account_id' => $user?->getAccount()?->getId(),
            'allowed' => $allowed,
        ]);

        try {
            if ($allowed) {
                $bot->approveChatJoinRequest($request->chat->id, $request->from->id);
                $this->say($bot, $request->user_chat_id, $this->welcomeText($user));
            } else {
                $this->say($bot, $request->user_chat_id, $this->refusalText());
                $bot->declineChatJoinRequest($request->chat->id, $request->from->id);
            }
        } catch (\Throwable $t) {
            // Usually "USER_ALREADY_PARTICIPANT" or a request another admin answered first.
            $this->chatLogger->error('resident chat join request failed: ' . $t->getMessage(), [
                'telegram_id' => $telegramId,
                'allowed' => $allowed,
            ]);

            return;
        }

        $this->chatLogger->info($allowed ? 'resident chat: approved' : 'resident chat: declined', [
            'telegram_id' => $telegramId,
            'apartment' => $user?->getAccount()?->getApartmentNumber(),
        ]);
    }

    private function welcomeText(TelegramUser $user): string
    {
        $apartment = $user->getAccount()?->getApartmentNumber();

        return sprintf(
            "✅ <b>Вітаємо в чаті будинку!</b>\n\n"
            . "Вас впізнано%s — заходьте.\n\n"
            . '<i>У чаті ваш номер телефону не видно нікому: сусіди бачать лише ім’я, '
            . 'яке ви самі поставили в Telegram.</i>',
            $apartment ? ' (кв. ' . htmlspecialchars((string)$apartment, ENT_QUOTES, 'UTF-8') . ')' : '',
        );
    }

    private function refusalText(): string
    {
        return "🏘 <b>Чат будинку — тільки для мешканців</b>\n\n"
            . "Щоб зайти, ваш Telegram має бути прив’язаний до особового рахунку ОСББ. "
            . "Зараз він не прив’язаний, тому заявку відхилено.\n\n"
            . "Це робиться за хвилину:\n"
            . "1️⃣ Відкрийте бота та натисніть /phone\n"
            . "2️⃣ Поділіться своїм номером телефону\n"
            . "3️⃣ Якщо номер є в реєстрі ОСББ, бот прив’яже вас одразу\n\n"
            . "Після цього надішліть заявку ще раз — вона пройде автоматично.\n\n"
            . '<i>Якщо ви живете тут, але номера немає в реєстрі (наприклад, ви орендуєте '
            . 'квартиру) — попросіть власника додати вас, або зверніться до бухгалтера ОСББ.</i>';
    }

    /**
     * A message to someone who may never have opened the bot: it can fail for reasons
     * that are not our problem (they blocked the bot, another admin got there first),
     * and it must not take the approve/decline down with it.
     */
    private function say(Nutgram $bot, int $chatId, string $text): void
    {
        try {
            $bot->sendMessage(text: $text, chat_id: $chatId, parse_mode: ParseMode::HTML);
        } catch (\Throwable $t) {
            $this->chatLogger->warning('resident chat notice failed: ' . $t->getMessage(), [
                'chat_id' => $chatId,
            ]);
        }
    }
}
