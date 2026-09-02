<?php

namespace App\Service;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatMemberStatus;
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
    /**
     * Is this resident already inside the group?
     *
     * Asked at menu-render time so somebody who is already a member is not offered a
     * door they are standing behind. Returns null when Telegram could not be asked —
     * the caller then falls back to the ordinary invitation, because showing the door to
     * a member is a much smaller mistake than hiding it from someone who needs it.
     *
     * RESTRICTED is a member only when is_member is true: a restricted user who left is
     * still reported with that status.
     */
    public function isMember(Nutgram $bot, TelegramUser $user): ?bool
    {
        if (!$this->isConfigured() || !$user->getTelegramId()) {
            return null;
        }

        try {
            $member = $bot->getChatMember((int)$this->residentChatId, (int)$user->getTelegramId());
        } catch (\Throwable $t) {
            $this->chatLogger->info('resident chat membership check failed: ' . $t->getMessage(), [
                'telegram_id' => $user->getTelegramId(),
            ]);

            return null;
        }

        $status = $member?->status;
        $status = $status instanceof ChatMemberStatus ? $status : ChatMemberStatus::tryFrom((string)$status);

        return match ($status) {
            ChatMemberStatus::CREATOR,
            ChatMemberStatus::ADMINISTRATOR,
            ChatMemberStatus::MEMBER => true,
            ChatMemberStatus::RESTRICTED => (bool)($member->is_member ?? false),
            default => false,
        };
    }

    public function handleJoinRequest(Nutgram $bot, ChatJoinRequest $request): void
    {
        $telegramId = (string)$request->from->id;

        // Logged before the guard below, and with the chat id, because the first thing
        // anyone debugging this asks is "did the knock even reach us, and from where?" —
        // a mismatching id is the difference between a misconfigured gate and a silent
        // Telegram, and the two look identical from the outside.
        $this->chatLogger->info('join request received', [
            'chat_id' => (string)$request->chat->id,
            'chat_title' => $request->chat->title,
            'configured_chat_id' => $this->residentChatId,
            'telegram_id' => $telegramId,
            'username' => $request->from->username,
        ]);

        // The bot may sit in other chats; only this one is gated.
        if ((string)$request->chat->id !== $this->residentChatId) {
            return;
        }

        $user = $this->telegramUserRepository->getByTelegramId($telegramId);

        // Captured before mayJoin(), which resolves an account through the conditional-owner
        // phone and silently persists the link — after it has run, a user who arrived
        // unlinked is indistinguishable from one who was linked all along.
        $linkedBefore = $user?->getAccount()?->getId();

        $allowed = $user && $this->mayJoin($user);

        // "Чому мене не пустило?" is a question ~140 residents can ask, and the answer has
        // to come from this line. Logging the account id alone could not give it: "no such
        // user", "user with no phone" and "user with a phone that matches no account" all
        // came out as account_id=null and were indistinguishable from each other.
        //
        // The gap showed up on 02.09.2026, when the same telegram_id was approved, refused
        // and approved again within seven minutes and the log could not explain it. That
        // one turned out to be benign — the owner was testing the gate while the account
        // link was still being wired up by hand — but working that out took the nginx log,
        // the audit table and a read of every code path that touches the link. These
        // fields answer it directly.
        $this->chatLogger->info('gate decision', [
            'telegram_id' => $telegramId,
            'user_found' => $user !== null,
            'user_id' => $user?->getId(),
            'has_phone' => $user?->getPhoneNumber() !== null,
            'account_id' => $user?->getAccount()?->getId(),
            'linked_before_resolve' => $linkedBefore,
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
