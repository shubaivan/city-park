<?php

namespace App\Telegram\ApprovePhone\Command;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

/**
 * The door: a resident tells the bot which number they are, and the bot finds their flat.
 *
 * This is the first screen almost everybody sees, and it said «Подтрібно натиснути» over a
 * button shouting «Підтвердіть ВАШ телефон» — half a Russian word, no explanation of why a
 * bot is asking for a phone number, and capitals that read as an order. A person whose
 * number is in the ОСББ registry has nothing to lose by pressing it; a person who cannot
 * tell what it is for closes the bot.
 *
 * **It answers with where the person stands, not only with the button** (23.10.2026).
 * `/phone` was reachable only as a blue word inside other messages — it was in neither the
 * slash menu nor the main menu — so Helen G went looking for «PHONE» in the slash list,
 * did not find it, and asked the Viber group for a screenshot. Now it is in both, and the
 * same screen tells somebody who already shared their number that they did: «✅ номер
 * підтверджено» with the flat it matched, or «номер є, але в реєстрі його немає» with what
 * to do about it. Asking a confirmed resident to share again would read as the bot having
 * forgotten them.
 */
class ApprovePhoneCommand extends Command
{
    public const MENU_CALLBACK = 'phone-status';

    /** No number shared yet — the bot cannot recognise them at all. */
    public const STATE_NONE = 'none';
    /** A number is saved but matches no object in the ОСББ registry. */
    public const STATE_UNKNOWN = 'unknown';
    /** Linked to an object: the bot knows which flat this is. */
    public const STATE_CONFIRMED = 'confirmed';

    protected string $command = 'phone';
    protected ?string $description = '📱 Підтвердити номер телефону';

    public function handle(Nutgram $bot): void
    {
        $this->render($bot);
    }

    public function __invoke(Nutgram $bot): mixed
    {
        if ($bot->isCallbackQuery()) {
            try {
                $bot->answerCallbackQuery();
            } catch (\Throwable) {
                // the screen below is the answer; a lost spinner is cosmetic
            }
        }

        $this->render($bot);

        return null;
    }

    /**
     * Linked beats everything else: somebody the accountant attached to a flat by hand
     * may never have shared a number, and telling them «поділіться номером» would send
     * a confirmed resident through a door they are already inside.
     */
    public static function state(?TelegramUser $user, ?Account $account): string
    {
        if ($account instanceof Account) {
            return self::STATE_CONFIRMED;
        }

        if ($user instanceof TelegramUser && trim((string)$user->getPhoneNumber()) !== '') {
            return self::STATE_UNKNOWN;
        }

        return self::STATE_NONE;
    }

    public static function menuLabel(string $state): string
    {
        return match ($state) {
            self::STATE_CONFIRMED => '✅ Телефон підтверджено',
            self::STATE_UNKNOWN => '📱 Номер не знайдено в реєстрі ОСББ',
            default => '📱 Поділитися номером телефону',
        };
    }

    private function render(Nutgram $bot): void
    {
        [$user, $account] = self::currentUserAndAccount($bot);
        $state = self::state($user, $account);

        if ($state === self::STATE_NONE) {
            // A request_contact button exists only on a reply keyboard, which an inline
            // message cannot be edited into — so this is always a new message.
            $bot->sendMessage(
                text: "📱 <b>Підтвердження номера телефону</b>\n\n"
                    . "Натисніть кнопку внизу — Telegram надішле ваш номер, і бот знайде вашу "
                    . "квартиру в реєстрі ОСББ.\n\n"
                    . '<i>Номер уже є в ОСББ — ви давали його для нарахувань. Бот нікому його '
                    . 'не показує: він потрібен лише для того, щоб упізнати вас.</i>',
                parse_mode: ParseMode::HTML,
                reply_markup: ReplyKeyboardMarkup::make(one_time_keyboard: true, resize_keyboard: true)->addRow(
                    KeyboardButton::make('📱 Поділитися номером телефону', true),
                )
            );

            return;
        }

        $text = $state === self::STATE_CONFIRMED
            ? self::confirmedText($user, $account)
            : EventApprovePhoneCommand::notFoundText();

        $markup = InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton());

        if ($bot->isCallbackQuery()) {
            try {
                $bot->editMessageText(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);

                return;
            } catch (\Throwable) {
                // fall through to a new message
            }
        }

        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $markup);
    }

    /**
     * The number is shown in full: it is the reader's own, and «який саме номер бот
     * знає» is the question that brings somebody to this screen after changing SIM.
     */
    public static function confirmedText(?TelegramUser $user, Account $account): string
    {
        $phone = $user instanceof TelegramUser ? trim((string)$user->getPhoneNumber()) : '';

        return "✅ <b>Ваш номер підтверджено</b>\n\n"
            . ($phone !== ''
                ? sprintf("📱 %s\n", self::esc(str_starts_with($phone, '+') ? $phone : '+' . $phone))
                : '')
            . sprintf("🏠 %s\n", self::esc($account->getStreetPlaceLabel()))
            . sprintf("🧾 Особовий рахунок: <code>%s</code>\n\n", self::esc((string)$account->getAccountNumber()))
            . 'Бот вас упізнає — всі можливості для мешканців ЖК відкриті. '
            . 'Нічого більше робити не потрібно.';
    }

    /** @return array{0: ?TelegramUser, 1: ?Account} */
    private static function currentUserAndAccount(Nutgram $bot): array
    {
        try {
            $service = $bot->getContainer()->get(TelegramUserService::class);

            if (!$service instanceof TelegramUserService) {
                return [null, null];
            }

            // getCurrentUser() reads an uninitialised typed property outside a webhook
            // request — an Error, not a null — hence the catch below.
            $user = $service->getCurrentUser();

            return [$user, $user ? $service->resolveAccount($user) : null];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
