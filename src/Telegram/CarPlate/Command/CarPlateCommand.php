<?php

namespace App\Telegram\CarPlate\Command;

use App\Entity\Account;
use App\Entity\CarPlate;
use App\Service\CarPlateService;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * «🚗 Мої авто» — the household's car numbers, for the house's own list of cars.
 *
 * **Optional, and the screen says so first.** It was asked for out of the parking
 * discussion as a list the ЖК can use for access later; nothing in the bot depends on it,
 * and a resident who reads it as one more obligation will either ignore the bot or ring
 * the ОСББ to ask whether they must. Every screen here leads with «за бажанням».
 */
class CarPlateCommand
{
    public const MENU_CALLBACK = 'car:list';
    public const DELETE_PREFIX = 'car:del:';
    public const DELETE_OK_PREFIX = 'car:delok:';
    public const VIEW_PREFIX = 'car:view:';

    public function __construct(
        private CarPlateService $plates,
        private TelegramUserService $telegramUserService,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $data = $bot->isCallbackQuery() ? (string)($bot->callbackQuery()->data ?? '') : '';
        $account = $this->account();

        if (!$account instanceof Account) {
            $bot->isCallbackQuery() && $bot->answerCallbackQuery();
            $bot->sendMessage(
                text: "🚗 <b>Мої авто</b>\n\n"
                    . "Щоб вносити номери авто, бот має знати вашу квартиру.\n"
                    . 'Натисніть /phone і поділіться номером телефону.',
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()->addRow(StartCommand::homeButton()),
            );

            return;
        }

        if (str_starts_with($data, self::VIEW_PREFIX)) {
            $this->view($bot, $account, (int)substr($data, strlen(self::VIEW_PREFIX)));

            return;
        }

        if (str_starts_with($data, self::DELETE_PREFIX)) {
            $this->askDelete($bot, $account, (int)substr($data, strlen(self::DELETE_PREFIX)));

            return;
        }

        if (str_starts_with($data, self::DELETE_OK_PREFIX)) {
            $this->delete($bot, $account, (int)substr($data, strlen(self::DELETE_OK_PREFIX)));

            return;
        }

        $this->list($bot, $account);
    }

    public function list(Nutgram $bot, Account $account, string $notice = ''): void
    {
        $bot->isCallbackQuery() && $bot->answerCallbackQuery();

        $plates = $this->plates->forAccount($account);
        $markup = InlineKeyboardMarkup::make();

        foreach ($plates as $plate) {
            $markup->addRow(InlineKeyboardButton::make(
                '🚗 ' . $plate->getDisplay() . ($plate->getParkingSpot() !== null ? ' · 🅿️ ' . $plate->getParkingSpot() : ''),
                callback_data: self::VIEW_PREFIX . $plate->getId(),
            ));
        }

        if ($this->plates->mayAdd($account)) {
            $markup->addRow(InlineKeyboardButton::make('➕ Додати номер авто', callback_data: CarPlateAdd::START_CALLBACK));
        }

        $markup->addRow(StartCommand::homeButton());

        $bot->sendMessage(
            text: ($notice !== '' ? $notice . "\n\n" : '')
                . self::intro()
                . "\n\n" . self::listBlock($account, $plates),
            parse_mode: ParseMode::HTML,
            reply_markup: $markup,
        );
    }

    /** What this is and that nobody has to — said before anything else on the screen. */
    public static function intro(): string
    {
        return "🚗 <b>Мої авто</b>\n\n"
            . "<b>За бажанням, не обов'язково.</b> Внесіть номери автомобілів вашої квартири — "
            . "так у ЖК складеться перелік авто мешканців, який можна буде використати для "
            . "доступу на паркінг. Якщо є паркомісце — його теж можна вказати.\n\n"
            . '<i>Номери бачите ви й ваша родина в боті, охорона та ОСББ. Сусіди їх не бачать.</i>';
    }

    /** @param CarPlate[] $plates */
    private static function listBlock(Account $account, array $plates): string
    {
        $place = self::esc($account->getPlaceLabel());

        if ($plates === []) {
            return sprintf('<i>За %s поки не внесено жодного номера.</i>', $place);
        }

        $lines = array_map(
            static fn (CarPlate $p): string => sprintf(
                '• <b>%s</b>%s%s',
                $p->getDisplay(),
                $p->getParkingSpot() !== null ? ' · 🅿️ ' . self::esc($p->getParkingSpot()) : '',
                $p->getAddedBy() !== null
                    ? sprintf(' <i>— додав(ла) %s, %s</i>', self::esc($p->getAddedBy()->getDisplayName()), $p->getCreatedAt()->format('d.m'))
                    : '',
            ),
            $plates,
        );

        $tail = count($plates) >= CarPlate::MAX_PER_ACCOUNT
            ? sprintf("\n\n<i>Це максимум — %d номерів на квартиру. Видаліть зайвий, щоб додати інший.</i>", CarPlate::MAX_PER_ACCOUNT)
            : "\n\n<i>Щоб вказати паркомісце або видалити номер, натисніть на нього нижче.</i>";

        return sprintf("Авто за %s:\n%s", $place, implode("\n", $lines)) . $tail;
    }

    /** One car: its space, and the two things that can be done to it. */
    private function view(Nutgram $bot, Account $account, int $id): void
    {
        $bot->answerCallbackQuery();

        $plate = $this->plates->own($account, $id);

        if ($plate === null) {
            $this->list($bot, $account, '⚠️ Цей номер уже видалено.');

            return;
        }

        $bot->sendMessage(
            text: sprintf(
                "🚗 <b>%s</b>\n🅿️ Паркомісце: %s",
                $plate->getDisplay(),
                $plate->getParkingSpot() !== null ? '<b>' . self::esc($plate->getParkingSpot()) . '</b>' : '<i>не вказано</i>',
            ),
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    $plate->getParkingSpot() !== null ? '🅿️ Змінити паркомісце' : '🅿️ Вказати паркомісце',
                    callback_data: CarPlateSpot::START_PREFIX . $plate->getId(),
                ))
                ->addRow(InlineKeyboardButton::make('🗑 Видалити номер', callback_data: self::DELETE_PREFIX . $plate->getId()))
                ->addRow(InlineKeyboardButton::make('⬅️ До списку', callback_data: self::MENU_CALLBACK)),
        );
    }

    private function askDelete(Nutgram $bot, Account $account, int $id): void
    {
        $bot->answerCallbackQuery();

        $plate = $this->plates->own($account, $id);

        if ($plate === null) {
            $this->list($bot, $account, '⚠️ Цей номер уже видалено.');

            return;
        }

        $bot->sendMessage(
            text: sprintf('🗑 Видалити номер <b>%s</b> зі списку вашої квартири?', $plate->getDisplay()),
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('🗑 Так, видалити', callback_data: self::DELETE_OK_PREFIX . $plate->getId()),
                InlineKeyboardButton::make('⬅️ Ні', callback_data: self::MENU_CALLBACK),
            ),
        );
    }

    private function delete(Nutgram $bot, Account $account, int $id): void
    {
        $plate = $this->plates->own($account, $id);
        $display = $plate?->getDisplay();

        if ($plate !== null) {
            $this->plates->remove($plate);
        }

        $this->list($bot, $account, $display !== null ? sprintf('✅ Номер <b>%s</b> видалено.', $display) : '');
    }

    private function account(): ?Account
    {
        $user = $this->telegramUserService->getCurrentUser();

        return $user ? $this->telegramUserService->resolveAccount($user) : null;
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
