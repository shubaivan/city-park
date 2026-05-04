<?php

namespace App\Telegram\SchedulePavilion\Command;

use App\Entity\ScheduledSet;
use App\Service\SchedulePavilionService;
use App\Service\TelegramUserService;
use App\Service\UkDateFormatter;
use App\Service\WeatherService;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SchedulePavilion extends Conversation
{
    protected ?string $step = 'choosePavilion';
    public ?string $pavilion;
    public ?string $month;
    public ?string $day;
    public ?string $hour;

    public function __construct(
        protected string $projectDir,
        private SchedulePavilionService $schedulePavilionService,
        private EntityManagerInterface $em,
        private TelegramUserService $telegramUserService,
        private ValidatorInterface $validator,
        private WeatherService $weatherService,
    ) {}

    public function choosePavilion(Nutgram $bot)
    {
        if (!$this->telegramUserService->getCurrentUser()->getPhoneNumber()) {
            $bot->sendMessage(
                text: 'Підтвердження телефону обов\'язкове'
            );
            $bot->sendMessage(
                text: 'Подтрібно натиснути *Підтвердіть ВАШ телефон*',
                parse_mode: ParseMode::MARKDOWN,
                reply_markup: ReplyKeyboardMarkup::make(one_time_keyboard: true)->addRow(
                    KeyboardButton::make('Підтвердіть ВАШ телефон', true),
                )
            );
            return;
        }

        if (!$this->telegramUserService->getCurrentUser()->getAccount()) {
            $bot->sendMessage(
                text: 'Ви не можете бронювати! Ваш Аккаунт не підтверджений ОСББ. Зв\'яжітся з Аліною Бухгалтером - +380 93 658 32 02'
            );
            return;
        }

        if (!$this->telegramUserService->getCurrentUser()->getAccount()->isActive()) {
            $bot->sendMessage(
                text: 'Ви не можете бронювати! Ваш Аккаунт не активний. Зв\'яжітся з Аліною Бухгалтером - +380 93 658 32 02'
            );
            return;
        }

        if ($this->telegramUserService->getCurrentUser()->getAccount()->hasDebt()) {
            $debt = $this->telegramUserService->getCurrentUser()->getAccount()->getDebt();
            $bot->sendMessage(
                text: sprintf(
                    "❌ Ви не можете бронювати!\n\nУ вас є борг: <b>%s грн</b>\n\nБудь ласка, сплатіть борг для можливості бронювання.",
                    number_format((float)$debt, 2, '.', ' ')
                ),
                parse_mode: ParseMode::HTML
            );
            return;
        }

        $markup = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('Перша', callback_data: 'number_1'),
                InlineKeyboardButton::make('Друга', callback_data: 'number_2')
            );

        $bot->sendMessage(text: 'Оберіть альтанку', reply_markup: $markup);
        $this->next('chooseMonth');
    }

    public function chooseMonth(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || !str_contains($bot->callbackQuery()->data, 'number_')) {
            $this->choosePavilion($bot);
            return;
        }
        $this->pavilion = str_replace('number_', '', $bot->callbackQuery()->data);

        $this->renderMonthPicker($bot);
    }

    public function chooseDay(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            $this->choosePavilion($bot);
            return;
        }

        $data = $bot->callbackQuery()->data;

        if ($data === "0" || $data === 'back') {
            $this->renderPavilionPicker($bot);
            return;
        }

        if (!str_contains($data, 'month_')) {
            $this->choosePavilion($bot);
            return;
        }

        $this->month = str_replace('month_', '', $data);
        $this->renderDayPicker($bot);
    }

    public function chooseTimeSet(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            $this->choosePavilion($bot);
            return;
        }

        $data = $bot->callbackQuery()->data;

        if ($data === "0") {
            $this->renderPavilionPicker($bot);
            return;
        }

        if ($data === 'back') {
            $this->renderMonthPicker($bot);
            return;
        }

        if (!str_contains($data, 'day_')) {
            $this->choosePavilion($bot);
            return;
        }

        $this->day = str_replace('day_', '', $data);
        $this->renderTimePicker($bot);
    }

    public function scheduleDate(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            $this->choosePavilion($bot);
            return;
        }

        $data = $bot->callbackQuery()->data;

        if ($data === "0") {
            $this->renderPavilionPicker($bot);
            return;
        }

        if ($data === 'back') {
            $this->renderDayPicker($bot);
            return;
        }

        if (!str_contains($data, 'hour_')) {
            $this->choosePavilion($bot);
            return;
        }

        $this->hour = str_replace('hour_', '', $data);

        $current = SchedulePavilionService::createNewDate();
        $dateTime = SchedulePavilionService::createNewDate();
        $dateTime->setDate((int)$current->format('Y'), (int)$this->month, (int)$this->day);
        $dateTime->setTime((int)$this->hour, 0);
        $pavilionName = $this->pavilion == '1' ? 'Перша' : 'Друга';

        $this->safeEdit($bot,
            sprintf("🏠 Альтанка: <b>%s</b>\n📅 Дата: <b>%s</b>\n⏰ Час: <b>%s</b>\n\nЯкщо згодні натисніть <b>Підтверджую</b>",
                $pavilionName, UkDateFormatter::dayDate($dateTime), UkDateFormatter::time($dateTime)),
            InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make(text: '✅ Підтверджую', callback_data: 'confirm'),
                InlineKeyboardButton::make(text: '⬅️ Назад', callback_data: 'back'),
                InlineKeyboardButton::make(text: 'На початок', callback_data: 0),
            ),
            ParseMode::HTML
        );
        $this->next('approveDate');
    }

    public function approveDate(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            $this->choosePavilion($bot);
            return;
        }

        $data = $bot->callbackQuery()->data;

        if ($data === "0") {
            $this->renderPavilionPicker($bot);
            return;
        }

        if ($data === 'back') {
            $this->renderTimePicker($bot);
            return;
        }

        if ($data !== 'confirm') {
            $this->choosePavilion($bot);
            return;
        }

        $scheduledSet = (new ScheduledSet())
            ->setTelegramUserId($this->telegramUserService->getCurrentUser())
            ->setYear((int)(SchedulePavilionService::createNewDate())->format('Y'))
            ->setMonth((int)$this->month)
            ->setDay((int)$this->day)
            ->setHour((int)$this->hour)
            ->setPavilion((int)$this->pavilion);
        $scheduledSet->setScheduledAt($scheduledSet->getScheduledDateTime());

        $this->em->persist($scheduledSet);

        $lists = $this->validator->validate($scheduledSet);
        if (count($lists)) {
            $errorMsg = $lists[0]->getMessage();
            $this->safeEdit($bot, '<b>' . $errorMsg . '</b>', null, ParseMode::HTML);
            $this->end();
            return;
        }
        $this->em->flush();

        $dateTime = SchedulePavilionService::createNewDate();
        $dateTime->setDate((int)$dateTime->format('Y'), (int)$this->month, (int)$this->day);
        $dateTime->setTime((int)$this->hour, 0);
        $pavilionName = $this->pavilion == '1' ? 'Перша' : 'Друга';

        $this->safeEdit($bot,
            sprintf(
                "🎉🎉🎉\n\n<b>Бронювання підтверджено!</b>\n\n🏠 Альтанка: <b>%s</b>\n📅 Дата: <b>%s</b>\n⏰ Час: <b>%s</b>\n\n📲 Нагадування прийде за 15 хвилин до початку.\n\n🎉🎉🎉",
                $pavilionName,
                UkDateFormatter::dayDate($dateTime),
                UkDateFormatter::time($dateTime)
            ),
            null,
            ParseMode::HTML
        );

        // Send pavilion photo as final confirmation
        $file = sprintf('%s/assets/img/pavilion%s', $this->projectDir, $this->pavilion);
        if (is_file($file) && is_readable($file)) {
            $photo = fopen($file, 'r+');
            $bot->sendPhoto(
                photo: InputFile::make($photo),
                caption: sprintf('🏠 Альтанка: <b>%s</b>, 📅 <b>%s</b> ⏰ <b>%s</b>', $pavilionName, UkDateFormatter::dayDate($dateTime), UkDateFormatter::time($dateTime)),
                parse_mode: ParseMode::HTML,
            );
        }

        $this->end();
    }

    // --- Render helpers: always edit the callback message ---

    private function safeEdit(Nutgram $bot, string $text, ?InlineKeyboardMarkup $markup = null, ?ParseMode $parseMode = null): void
    {
        try {
            $bot->editMessageText(text: $text, parse_mode: $parseMode, reply_markup: $markup);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function renderPavilionPicker(Nutgram $bot): void
    {
        $this->safeEdit($bot, 'Оберіть альтанку',
            InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('Перша', callback_data: 'number_1'),
                InlineKeyboardButton::make('Друга', callback_data: 'number_2')
            )
        );
        $this->next('chooseMonth');
    }

    private function renderMonthPicker(Nutgram $bot): void
    {
        $current = SchedulePavilionService::createNewDate();
        $currentYear = (int)$current->format('Y');
        $currentMonth = (int)$current->format('m');
        $lastMonth = 12;

        $kb = InlineKeyboardMarkup::make();
        $row = [];
        for ($i = $currentMonth; $i <= $lastMonth; $i++) {
            $format = UkDateFormatter::monthEmoji($i) . UkDateFormatter::monthName($i);
            $row[] = InlineKeyboardButton::make(text: $format, callback_data: 'month_' . str_pad($i, 2, '0', STR_PAD_LEFT));
            if (count($row) == 3) {
                $kb->addRow(...$row);
                $row = [];
            }
        }
        if (count($row)) {
            $kb->addRow(...$row);
        }
        $kb->addRow(
            InlineKeyboardButton::make(text: '⬅️ Назад', callback_data: 'back'),
            InlineKeyboardButton::make(text: 'На початок', callback_data: 0),
        );

        $pavilionName = $this->pavilion == '1' ? 'Перша' : 'Друга';
        $this->safeEdit($bot, 'Альтанка: ' . $pavilionName . "\nОберіть місяць:", $kb);
        $this->next('chooseDay');
    }

    private function renderDayPicker(Nutgram $bot): void
    {
        $current = SchedulePavilionService::createNewDate();
        $currentYear = (int)$current->format('Y');

        $currentDay = ($this->month === $current->format('m')) ? (int)$current->format('d') : 1;
        $current->setDate($currentYear, (int)$this->month, $currentDay);
        $lastDay = (int)(clone $current)->modify('last day of')->format('d');

        $kb = InlineKeyboardMarkup::make();
        $row = [];
        for ($i = $currentDay; $i <= $lastDay; $i++) {
            if ($i != $currentDay) {
                $current->modify('+1 day');
            }
            $weekday = (int)$current->format('N');
            $format = UkDateFormatter::dayNameShort($weekday) . $current->format('d');
            $isWeekend = in_array($weekday, [6, 7], true);
            $weatherEmoji = $this->weatherService->getDayEmoji($current);
            $suffix = '';
            if ($isWeekend) {
                $suffix .= ' 🌴';
            }
            if ($weatherEmoji !== null) {
                $suffix .= ' ' . $weatherEmoji;
            }
            $label = $format . $suffix;
            $row[] = InlineKeyboardButton::make(text: $label, callback_data: 'day_' . $current->format('d'));
            if (count($row) == 3) {
                $kb->addRow(...$row);
                $row = [];
            }
        }
        if (count($row)) {
            $kb->addRow(...$row);
        }
        $kb->addRow(
            InlineKeyboardButton::make(text: '⬅️ Назад', callback_data: 'back'),
            InlineKeyboardButton::make(text: 'На початок', callback_data: 0),
        );

        $monthFormatted = UkDateFormatter::monthEmoji((int)$this->month) . ' <b>' . UkDateFormatter::monthName((int)$this->month) . '</b>';
        $pavilionName = $this->pavilion == '1' ? 'Перша' : 'Друга';
        $this->safeEdit($bot, 'Альтанка: ' . $pavilionName . ', Місяць: ' . $monthFormatted . "\nОберіть день:", $kb, ParseMode::HTML);
        $this->next('chooseTimeSet');
    }

    private function renderTimePicker(Nutgram $bot): void
    {
        $current = SchedulePavilionService::createNewDate();
        $currentYear = (int)$current->format('Y');

        $scheduledSets = $this->schedulePavilionService->getExistSet(
            $this->pavilion, $currentYear, (int)$this->month, (int)$this->day
        );

        $chosenDate = SchedulePavilionService::createNewDate();
        $chosenDate->setDate($currentYear, (int)$this->month, (int)$this->day);
        $chosenDate->setTime(0, 0);

        if ($current->format('Y-m-d') == $chosenDate->format('Y-m-d')) {
            $currentHour = (int)$current->format('H') + 1;
        } else {
            $currentHour = 0;
        }

        $accountBookedHours = [];
        $accountPavilionHours = [];
        $account = $this->telegramUserService->getCurrentUser()->getAccount();
        if ($account) {
            $accountBookedHours = $this->schedulePavilionService->getAccountBookedHours(
                $currentYear, (int)$this->month, (int)$this->day, $account
            );
            $accountPavilionHours = $this->schedulePavilionService->getAccountBookedHoursAtPavilion(
                (int)$this->pavilion, $currentYear, (int)$this->month, (int)$this->day, $account
            );
        }

        $availableHours = [];
        for ($i = $currentHour; $i < 24; $i++) {
            if (!array_key_exists($i, $scheduledSets) && !in_array($i, $accountBookedHours, true)) {
                $availableHours[] = $i;
            }
        }

        $isWeekend = in_array((int)$chosenDate->format('N'), [6, 7], true);

        $kb = InlineKeyboardMarkup::make();
        $row = [];
        foreach ($availableHours as $h) {
            $format = UkDateFormatter::hourEmoji($h) . $chosenDate->setTime($h, 0)->format('H:i');
            $isOrphan = false;
            foreach ($accountPavilionHours as $existing) {
                if (abs($h - $existing) !== 2) {
                    continue;
                }
                $middle = (int)(($h + $existing) / 2);
                if (in_array($middle, $accountPavilionHours, true)) {
                    continue;
                }
                $isOrphan = true;
                break;
            }
            $label = $isOrphan ? '⚠️ ' . $format : $format;
            $row[] = InlineKeyboardButton::make(text: $label, callback_data: 'hour_' . $chosenDate->format('H'));
            if (count($row) == 3) {
                $kb->addRow(...$row);
                $row = [];
            }
        }
        if (count($row)) {
            $kb->addRow(...$row);
        }
        $kb->addRow(
            InlineKeyboardButton::make(text: '⬅️ Назад', callback_data: 'back'),
            InlineKeyboardButton::make(text: 'На початок', callback_data: 0),
        );

        $other = [];
        foreach ($scheduledSets as $set) {
            if ($set->getTelegramUserId()->getTelegramId() != $this->telegramUserService->getCurrentUser()->getTelegramId()) {
                $other[] = $set;
            }
        }

        $dayDate = (clone SchedulePavilionService::createNewDate())->setDate($currentYear, (int)$this->month, (int)$this->day);
        $dayFormatted = '<b>' . UkDateFormatter::dayDate($dayDate) . '</b>';
        $pavilionName = $this->pavilion == '1' ? 'Перша' : 'Друга';
        $weekendSuffix = $isWeekend ? ' 🌴 (вихідний)' : '';
        $parts = ['🏠 Альтанка: <b>' . $pavilionName . '</b>, 📅 День: ' . $dayFormatted . $weekendSuffix];

        $forecast = $this->weatherService->getDailyForecastLine($dayDate);
        if ($forecast !== null) {
            $parts[] = 'Прогноз: ' . $forecast;
        }

        if ($other) {
            $parts[] = '';
            $parts[] = '<b>Чужі бронювання:</b>';
            foreach ($other as $set) {
                $key = str_pad($set->getHour(), 2, '0', STR_PAD_LEFT);
                $parts[] = '  година ' . $key . ':00, заброньована: ' . $set->getTelegramUserId()->concatNameInfo();
            }
        }

        $parts[] = '';
        $parts[] = count($availableHours) ? 'Оберіть час нового бронювання:' : 'Нажаль немає доступних бронювань. Оберіть іншу дату.';

        $this->safeEdit($bot, implode("\n", $parts), $kb, ParseMode::HTML);
        $this->next('scheduleDate');
    }

}
