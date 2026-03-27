<?php

namespace App\Telegram\SchedulePavilion\Command;

use App\Entity\ScheduledSet;
use App\Service\SchedulePavilionService;
use App\Service\TelegramUserService;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
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
        private ValidatorInterface $validator
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
                text: sprintf('Ви не можете бронювати! Ваш Аккаунт не підтверджений ОСББ. Зв\'яжітся з Аліною Бухгалтером - +380 93 658 32 02')
            );
            return;
        }

        if (!$this->telegramUserService->getCurrentUser()->getAccount()->isActive()) {
            $bot->sendMessage(
                text: sprintf('Ви не можете бронювати! Ваш Аккаунт не активнийю Зв\'яжітся з Аліною Бухгалтером - +380 93 658 32 02')
            );
            return;
        }

        $this->showPavilionPicker($bot);
    }

    private function showPavilionPicker(Nutgram $bot, bool $edit = false): void
    {
        $markup = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('Перша', callback_data: 'number_1'),
                InlineKeyboardButton::make('Друга', callback_data: 'number_2')
            );

        if ($edit) {
            $bot->editMessageText(
                text: 'Оберіть альтанку',
                reply_markup: $markup,
            );
        } else {
            $bot->sendMessage(
                text: 'Оберіть альтанку',
                reply_markup: $markup,
            );
        }
        $this->next('chooseMonth');
    }

    private function showMonthPicker(Nutgram $bot, bool $edit = false): void
    {
        $current = SchedulePavilionService::createNewDate();
        $currentYear = (int)$current->format('Y');
        $currentMonth = (int)$current->format('m');
        $last = (clone $current)->modify('last day of december this year');
        $lastMonth = (int)$last->format('m');

        $inlineKeyboardMarkup = InlineKeyboardMarkup::make();
        $month = [];
        for ($i = $currentMonth; $i<=$lastMonth; $i++) {
            $chooseMonth = clone $current;
            $format = $chooseMonth->setDate($currentYear, $i, 1)->format('Y-m');
            $month[] = InlineKeyboardButton::make(
                text: $format, callback_data: 'month_' . $chooseMonth->format('m')
            );
            if (count($month) == 3) {
                $inlineKeyboardMarkup->addRow(...$month);
                $month = [];
            }
        }
        if (count($month)) {
            $inlineKeyboardMarkup->addRow(...$month);
        }
        $inlineKeyboardMarkup->addRow(
            InlineKeyboardButton::make(text: '⬅️ Назад', callback_data: 'back'),
            InlineKeyboardButton::make(text: 'На початок', callback_data: 0),
        );

        $pavilionName = $this->pavilion == '1' ? 'Перша' : 'Друга';
        $text = 'Альтанка: ' . $pavilionName . "\nОберіть місяць:";

        if ($edit) {
            $bot->editMessageText(text: $text, reply_markup: $inlineKeyboardMarkup);
        } else {
            $bot->sendMessage(text: $text, reply_markup: $inlineKeyboardMarkup);
        }
        $this->next('chooseDay');
    }

    private function showDayPicker(Nutgram $bot, bool $edit = false): void
    {
        $current = SchedulePavilionService::createNewDate();
        $currentYear = (int)$current->format('Y');

        if ($this->month === $current->format('m')) {
            $currentDay = (int)$current->format('d');
        } else {
            $currentDay = 1;
        }

        $current->setDate($currentYear, (int)$this->month, $currentDay);

        $last = (clone $current)->modify('last day of');
        $lastDay = (int)$last->format('d');

        $inlineKeyboardMarkup = InlineKeyboardMarkup::make();
        $days = [];
        for ($i = $currentDay; $i<=$lastDay; $i++) {
            if ($i == $currentDay) {
                $format = $current->format('M-d');
            } else {
                $format = $current->modify('+1 day')->format('M-d');
            }
            $days[] = InlineKeyboardButton::make(
                text: $format, callback_data: 'day_' . $current->format('d')
            );
            if (count($days) == 4) {
                $inlineKeyboardMarkup->addRow(...$days);
                $days = [];
            }
        }
        if (count($days)) {
            $inlineKeyboardMarkup->addRow(...$days);
        }
        $inlineKeyboardMarkup->addRow(
            InlineKeyboardButton::make(text: '⬅️ Назад', callback_data: 'back'),
            InlineKeyboardButton::make(text: 'На початок', callback_data: 0),
        );

        $monthFormatted = SchedulePavilionService::createNewDate()->setDate($currentYear, (int)$this->month, 1)->format('Y-m');
        $pavilionName = $this->pavilion == '1' ? 'Перша' : 'Друга';
        $text = 'Альтанка: ' . $pavilionName . ', Місяць: ' . $monthFormatted . "\nОберіть день:";

        if ($edit) {
            $bot->editMessageText(text: $text, reply_markup: $inlineKeyboardMarkup);
        } else {
            $bot->sendMessage(text: $text, reply_markup: $inlineKeyboardMarkup);
        }
        $this->next('chooseTimeSet');
    }

    public function chooseMonth(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || !str_contains($bot->callbackQuery()->data, 'number_')) {
            $this->choosePavilion($bot);
            return;
        }
        $this->pavilion = str_replace('number_', '', $bot->callbackQuery()->data);

        $file = sprintf(
            '%s/assets/img/pavilion%s',
            $this->projectDir,
            $this->pavilion
        );
        if (is_file($file) && is_readable($file)) {
            $photo = fopen($file, 'r+');
            $pavilionName = $this->pavilion == '1' ? 'Перша' : 'Друга';
            $bot->sendPhoto(
                photo: InputFile::make($photo),
                caption: '🏠 Альтанка: ' . $pavilionName,
            );
        }

        // Edit the "Оберіть альтанку" message into the month picker
        $this->showMonthPicker($bot, edit: true);
    }

    public function chooseDay(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            $this->choosePavilion($bot);
            return;
        }

        $data = $bot->callbackQuery()->data;

        if ($data === "0") {
            $this->showPavilionPicker($bot, edit: true);
            return;
        }

        if ($data === 'back') {
            $this->showPavilionPicker($bot, edit: true);
            return;
        }

        if (!str_contains($data, 'month_')) {
            $this->choosePavilion($bot);
            return;
        }

        $this->month = str_replace('month_', '', $data);

        // Edit the month picker message into the day picker
        $this->showDayPicker($bot, edit: true);
    }

    public function chooseTimeSet(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            $this->choosePavilion($bot);
            return;
        }

        $data = $bot->callbackQuery()->data;

        if ($data === "0") {
            $this->showPavilionPicker($bot, edit: true);
            return;
        }

        if ($data === 'back') {
            $this->showMonthPicker($bot, edit: true);
            return;
        }

        if (!str_contains($data, 'day_')) {
            $this->choosePavilion($bot);
            return;
        }

        $this->day = str_replace('day_', '', $data);

        $current = SchedulePavilionService::createNewDate();
        $currentYear = (int)$current->format('Y');

        $scheduledSets = $this->schedulePavilionService->getExistSet(
            $this->pavilion,
            $currentYear,
            (int)$this->month,
            (int)$this->day,
        );

        $chosenDate = SchedulePavilionService::createNewDate();
        $chosenDate->setDate($currentYear, (int)$this->month, (int)$this->day);
        $chosenDate->setTime(0,0);
        if ($current->format('Y-m-d') == $chosenDate->format('Y-m-d')) {
            $currentHour = (int)$current->format('H');
            $currentHour += 1;
            $chosenDate->setTime($currentHour, 0);
            $last = 24;
        } else {
            $currentHour = 0;
            $last = 24;
        }

        $availableHours = [];
        for ($i = $currentHour; $i < $last; $i++) {
            if (array_key_exists($i, $scheduledSets)) {
                continue;
            }
            $availableHours[] = $i;
        }

        $inlineKeyboardMarkup = InlineKeyboardMarkup::make();
        $hours = [];
        foreach ($availableHours as $availableHourItem) {
            $format = $chosenDate->setTime($availableHourItem, 0)->format('D/H-i');
            $hours[] = InlineKeyboardButton::make(
                text: $format, callback_data: 'hour_' . $chosenDate->format('H')
            );

            if (count($hours) == 3) {
                $inlineKeyboardMarkup->addRow(...$hours);
                $hours = [];
            }
        }

        if (count($hours)) {
            $inlineKeyboardMarkup->addRow(...$hours);
        }

        $inlineKeyboardMarkup->addRow(
            InlineKeyboardButton::make(text: '⬅️ Назад', callback_data: 'back'),
            InlineKeyboardButton::make(text: 'На початок', callback_data: 0),
        );

        $other = [];
        $ownSchedule = [];
        foreach ($scheduledSets as $set) {
            if ($set->getTelegramUserId()->getTelegramId() == $this->telegramUserService->getCurrentUser()->getTelegramId()) {
                $ownSchedule[] = $set;
            } else {
                $other[] = $set;
            }
        }

        // Send own bookings with cancel buttons separately (if any)
        if ($ownSchedule) {
            foreach ($ownSchedule as $set) {
                $scheduledByCurrentUserDate = $set->getScheduledDateTime();
                $key = strlen($set->getHour()) == 1 ? '0'.$set->getHour() : $set->getHour();

                $bot->sendMessage(
                    text: sprintf('Ваше бронювання: альтанка №%s, час: %s', $set->getPavilion(), $scheduledByCurrentUserDate->format('Y-m-d/H-i')),
                    parse_mode: ParseMode::HTML,
                    reply_markup: InlineKeyboardMarkup::make()
                        ->addRow(
                            InlineKeyboardButton::make(
                                'Відмінити', callback_data: 'decline_' . $key
                            ),
                        )
                );
            }
        }

        // Build summary text
        $dayFormatted = (clone SchedulePavilionService::createNewDate())->setDate($currentYear, (int)$this->month, (int)$this->day)->format('M-d');
        $pavilionName = $this->pavilion == '1' ? 'Перша' : 'Друга';
        $summaryParts = ['Альтанка: ' . $pavilionName . ', День: ' . $dayFormatted];

        if ($other) {
            $summaryParts[] = '';
            $summaryParts[] = '<b>Чужі бронювання:</b>';
            foreach ($other as $set) {
                $key = strlen($set->getHour()) == 1 ? '0' . $set->getHour() : $set->getHour();
                $summaryParts[] = sprintf('  година %s:00, заброньована: %s', $key, $set->getTelegramUserId()->concatNameInfo());
            }
        }

        $summaryParts[] = '';
        if (count($availableHours)) {
            $summaryParts[] = 'Оберіть час нового бронювання:';
        } else {
            $summaryParts[] = 'Нажаль немає доступних бронювань. Оберіть іншу дату.';
        }

        // Edit the day picker message into the time picker
        $bot->editMessageText(
            text: implode("\n", $summaryParts),
            parse_mode: ParseMode::HTML,
            reply_markup: $inlineKeyboardMarkup,
        );

        $this->next('scheduleDate');
    }

    public function scheduleDate(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            $this->choosePavilion($bot);
            return;
        }

        $data = $bot->callbackQuery()->data;

        if ($data === "0") {
            $this->showPavilionPicker($bot, edit: true);
            return;
        }

        if ($data === 'back') {
            $this->showDayPicker($bot, edit: true);
            return;
        }

        if (!str_contains($data, 'hour_') && !str_contains($data, 'decline_')) {
            $this->choosePavilion($bot);
            return;
        }

        $chosenHour = $data;
        if (str_contains($chosenHour, 'decline_')) {
            $this->hour = str_replace('decline_', '', $chosenHour);

            $current = SchedulePavilionService::createNewDate();
            $dateTime = SchedulePavilionService::createNewDate();
            $dateTime->setDate((int)$current->format('Y'), (int)$this->month, (int)$this->day);
            $dateTime->setTime((int)$this->hour,0);

            $bot->editMessageText(
                text: 'Видалити бронювання ' . $dateTime->format('Y/m/d H:i') . '? Натисніть <b>Підтверджую</b>',
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make(text: 'Підтверджую', callback_data: 1),
                    InlineKeyboardButton::make(text: 'На початок', callback_data: 0),
                )
            );

            $this->next('removeScheduled');
        } else {
            $this->hour = str_replace('hour_', '', $chosenHour);

            $current = SchedulePavilionService::createNewDate();
            $dateTime = SchedulePavilionService::createNewDate();
            $dateTime->setDate((int)$current->format('Y'), (int)$this->month, (int)$this->day);
            $dateTime->setTime((int)$this->hour,0);

            $bot->editMessageText(
                text: sprintf("Альтанка №%s. Дата: %s\nЯкщо згодні натисніть <b>Підтверджую</b>", $this->pavilion, $dateTime->format('Y/m/d H:i')),
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make(text: 'Підтверджую', callback_data: 1),
                    InlineKeyboardButton::make(text: '⬅️ Назад', callback_data: 'back'),
                    InlineKeyboardButton::make(text: 'На початок', callback_data: 0),
                )
            );

            $this->next('approveDate');
        }
    }

    public function removeScheduled(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery() || $bot->callbackQuery()->data != "1") {
            $this->choosePavilion($bot);

            return;
        }
        $current = SchedulePavilionService::createNewDate();
        $scheduledSets = $this->schedulePavilionService->getExistSet(
            $this->pavilion,
            (int)$current->format('Y'),
            (int)$this->month,
            (int)$this->day,
            (int)$this->hour,
            $this->telegramUserService->getCurrentUser()
        );
        if (!$scheduledSets) {
            $bot->editMessageText(
                text: '<b>Не знайшло ваше бронювання.</b>',
                parse_mode: ParseMode::HTML
            );

            $this->choosePavilion($bot);

            return;
        }
        $scheduledSet = array_shift($scheduledSets);
        $this->em->remove($scheduledSet);
        $this->em->flush();

        $bot->editMessageText(
            text: '<b>Ваше бронювання видалено.</b>',
            parse_mode: ParseMode::HTML
        );

        $this->end();
    }

    public function approveDate(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            $this->choosePavilion($bot);
            return;
        }

        $data = $bot->callbackQuery()->data;

        if ($data === "0") {
            $this->showPavilionPicker($bot, edit: true);
            return;
        }

        if ($data === 'back') {
            $this->showDayPicker($bot, edit: true);
            return;
        }

        if ($data != "1") {
            $this->choosePavilion($bot);
            return;
        }

        $scheduledSet = (new ScheduledSet())
            ->setTelegramUserId($this->telegramUserService->getCurrentUser())
            ->setYear((int)(SchedulePavilionService::createNewDate())->format('Y'))
            ->setMonth((int)$this->month)
            ->setDay((int)$this->day)
            ->setHour((int)$this->hour)
            ->setPavilion((int)$this->pavilion)
        ;
        $scheduledSet->setScheduledAt($scheduledSet->getScheduledDateTime());

        $this->em->persist($scheduledSet);

        $lists = $this->validator->validate($scheduledSet);
        if (count($lists)) {
            foreach ($lists as $list) {
                $bot->editMessageText(
                    text: '<b>'.$list->getMessage().'</b>',
                    parse_mode: ParseMode::HTML
                );
                $this->choosePavilion($bot);

                return;
            }
            $bot->editMessageText(
                text: '<b>Сталась помилка.</b>',
                parse_mode: ParseMode::HTML
            );
            $this->choosePavilion($bot);

            return;
        }
        $this->em->flush();

        $dateTime = SchedulePavilionService::createNewDate();
        $dateTime->setDate((int)$dateTime->format('Y'), (int)$this->month, (int)$this->day);
        $dateTime->setTime((int)$this->hour, 0);
        $pavilionName = $this->pavilion == '1' ? 'Перша' : 'Друга';

        $bot->editMessageText(
            text: sprintf(
                "🎉🎉🎉\n\n<b>Бронювання підтверджено!</b>\n\n🏠 Альтанка: <b>%s</b>\n📅 Дата: <b>%s</b>\n⏰ Час: <b>%s</b>\n\n📲 Нагадування прийде за 15 хвилин до початку.\n\n🎉🎉🎉",
                $pavilionName,
                $dateTime->format('d.m.Y'),
                $dateTime->format('H:i')
            ),
            parse_mode: ParseMode::HTML
        );

        $this->end();
    }
}
