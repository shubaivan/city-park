<?php

namespace App\Telegram\Start\Command;

use App\Entity\Account;
use App\Service\TelegramUserService;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class StartCommand extends Command
{
    protected string $command = 'start';
    protected ?string $description = 'Початок спілкування';

    public const MAIN_MENU_CALLBACK = 'main-menu';

    public function handle(Nutgram $bot): void
    {
        self::send($bot, edit: false);
    }

    public function __invoke(Nutgram $bot): mixed
    {
        $edit = $bot->isCallbackQuery();
        self::send($bot, edit: $edit);
        return null;
    }

    public static function send(Nutgram $bot, bool $edit = false): void
    {
        $text = self::header($bot) . 'Оберіть:';
        $markup = self::mainMenuMarkup();

        if ($edit) {
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
     * Address + особовий рахунок block shown above the menu.
     *
     * Residents kept confusing "рахунок" with a bank account (pasting IBANs when asked
     * for it), so the number is spelled out on every menu render — tap-to-copy via
     * <code>, and explicitly labelled as not-a-bank-account.
     *
     * Renders nothing when the account can't be resolved (unconfirmed phone, or a
     * family member not yet linked) so the menu never breaks.
     */
    private static function header(Nutgram $bot): string
    {
        $account = self::currentAccount($bot);

        if (!$account instanceof Account || !$account->getAccountNumber()) {
            return '';
        }

        $address = trim(sprintf(
            '%s %s',
            (string)$account->getStreet(),
            (string)$account->getHouseNumber(),
        ));

        $where = $address !== ''
            ? sprintf('%s, кв. %s', $address, $account->getApartmentNumber())
            : sprintf('кв. %s', $account->getApartmentNumber());

        return sprintf(
            "🏠 <b>%s</b>\n"
            . "🧾 Ваш особовий рахунок: <code>%s</code>\n"
            . "<i>Це ваш номер в ОСББ (не банківський) — називайте його, коли звертаєтесь до бухгалтера.</i>\n\n",
            self::esc($where),
            self::esc((string)$account->getAccountNumber()),
        );
    }

    /**
     * Dependencies are pulled from the bot container instead of the constructor:
     * Nutgram's registerCommand() instantiates Command subclasses with a plain
     * `new $class()` (MessageListeners::registerCommand), so a required constructor
     * argument would blow up at bot boot for every update. Method injection is out
     * too — Handler::__invoke(Nutgram $bot) fixes the signature. TelegramUserService
     * is therefore declared public in config/services.yaml so the delegated Symfony
     * container hands back the same shared instance RequestSubscriber init'd.
     */
    private static function currentAccount(Nutgram $bot): ?Account
    {
        try {
            $telegramUserService = $bot->getContainer()->get(TelegramUserService::class);

            if (!$telegramUserService instanceof TelegramUserService) {
                return null;
            }

            // getCurrentUser() reads an uninitialised typed property when
            // RequestSubscriber never ran (CLI / non-webhook contexts) — that is an
            // Error, not a null, so it has to be caught rather than checked.
            $user = $telegramUserService->getCurrentUser();
            if (!$user) {
                return null;
            }

            return $telegramUserService->resolveAccount($user);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function homeButton(): InlineKeyboardButton
    {
        return InlineKeyboardButton::make('🏠 На головну', callback_data: self::MAIN_MENU_CALLBACK);
    }

    private static function mainMenuMarkup(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            // Оренда sits first on purpose: it is the newest section and residents were
            // not finding it at the bottom of the menu, under three rows they already
            // know by heart. Booking is the everyday action and stays one tap away.
            ->addRow(
                InlineKeyboardButton::make('🔑 Оренда квартир', callback_data: 'rental-menu'),
            )
            ->addRow(
                InlineKeyboardButton::make('Бронювання', callback_data: 'schedule-pavilion'),
                InlineKeyboardButton::make('Переглянути свої', callback_data: 'own-schedule'),
                InlineKeyboardButton::make('Як доїхати?', callback_data: 'type:route'),
            )
            ->addRow(
                InlineKeyboardButton::make('📜 Історія бронювань', callback_data: 'booking-history'),
                InlineKeyboardButton::make('📸 Завантажити фото', callback_data: 'photo-upload-info'),
            )
            ->addRow(
                InlineKeyboardButton::make('ℹ️ Інструкція та FAQ', callback_data: 'info-menu'),
                InlineKeyboardButton::make('🗳️ Голосування', callback_data: 'voting-menu'),
            );
    }
}
