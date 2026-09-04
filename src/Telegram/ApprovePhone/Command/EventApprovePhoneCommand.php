<?php

namespace App\Telegram\ApprovePhone\Command;

use App\Service\OsbbContacts;
use App\Entity\Account;
use App\Service\TelegramUserService;
use App\Telegram\Location\Repository\OfficeRepository;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;

class EventApprovePhoneCommand extends Command
{
    protected string $command = 'eventContact';
    protected ?string $description = 'Підтвердіть ВАШ телефон';

    public function __construct(
        private TelegramUserService $telegramUserService,
        private EntityManagerInterface $em,
        $callable = null, ?string $command = null)
    {
        parent::__construct($callable, $command);
    }

    /**
     * Saving the number is not the same as being recognised, and until 02.09.2026 this
     * said otherwise: it answered «Підтверджено, дякуємо, тепер можете бронювати» to
     * everyone, including people whose number is in no ОСББ record at all. They then went
     * to the residents' chat and were refused at the door, believing they had just been
     * confirmed. Every such person is a support call, and the one who gives up instead is
     * a resident lost to the bot entirely.
     *
     * So the answer now depends on whether an Account actually resolved.
     */
    public function handle(Nutgram $bot): void
    {
        $this->telegramUserService->savePhone($bot->message()->contact->phone_number);
        $this->em->flush();

        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;

        $bot->sendMessage(
            text: $account instanceof Account ? $this->confirmedText($account) : $this->notFoundText(),
            parse_mode: ParseMode::HTML,
        );

        $bot->sendMessage(
            text: 'Removing keyboard...',
            reply_markup: ReplyKeyboardRemove::make(true),
        )?->delete();
    }

    /**
     * Names what was matched. A resident who sees their own address knows the bot found
     * the right record — and a family member who sees somebody else's says so immediately,
     * instead of discovering it a month later through a booking made on the wrong flat.
     */
    private function confirmedText(Account $account): string
    {
        $address = trim(sprintf('%s %s', (string)$account->getStreet(), (string)$account->getHouseNumber()));
        $where = $address !== ''
            ? sprintf('%s, кв. %s', $address, (string)$account->getApartmentNumber())
            : sprintf('кв. %s', (string)$account->getApartmentNumber());

        return sprintf(
            "✅ <b>Підтверджено, дякуємо!</b>\n\n"
            . "🏠 %s\n"
            . "🧾 Особовий рахунок: <code>%s</code>\n\n"
            . 'Тепер вам відкриті всі можливості для мешканців ЖК. Натисніть /start.',
            htmlspecialchars($where, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string)$account->getAccountNumber(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    private function notFoundText(): string
    {
        return "📕 <b>Номер збережено, але в реєстрі ОСББ його немає</b>\n\n"
            . "Це не помилка з вашого боку — просто цей номер не записаний за жодною квартирою, "
            . "тому можливості для мешканців ЖК поки закриті.\n\n"
            . "Що зробити:\n"
            . "• якщо ви <b>власник</b> — зверніться до бухгалтера ОСББ:\n" . OsbbContacts::accountant() . ",\n"
            . "щоб вона додала цей номер до вашого рахунку;\n"
            . "• якщо ви <b>член сім'ї або орендар</b> — попросіть власника квартири додати вас.\n\n"
            . '<i>Щойно номер з’явиться в реєстрі, натисніть /phone ще раз — і все відкриється.</i>';
    }
}