<?php

namespace App\Telegram\Voting\Command;

use App\Entity\Account;
use App\Entity\BlockVoteCampaign;
use App\Service\BlockVoteService;
use App\Service\OsbbContacts;
use App\Service\PhotoUploadFlow;
use App\Service\TelegramUserService;
use App\Telegram\Start\Command\StartCommand;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * "➕ Запропонувати питання": a resident asks the house something.
 *
 * **Anybody may ask, and it is live the moment they finish.** No approval to be granted, no
 * queue to sit in — the question appears in «🗳 Голосування» and neighbours can answer it.
 * What waits is only the *broadcast*: a DM to every voter and a post in the chat is the part
 * that rings 171 phones, and that needs somebody accountable, so an admin approves it — or
 * the question earns it by collecting {@see BlockVoteService::AUTO_BROADCAST_VOTES} ballots
 * on its own. That second path is the point: a question people actually care about cannot be
 * buried by nobody approving it.
 *
 * One open question per **account**, not per person: a household asking three things at once
 * is the failure mode this stops, and a family sharing one рахунок shares one turn.
 */
class VoteAsk extends Conversation
{
    public const START_CALLBACK = 'vote:ask';

    /** Long enough to be a question. «А шо?» is not one. */
    public const QUESTION_MIN = 15;
    public const QUESTION_MAX = 200;
    public const DETAILS_MAX = 600;

    protected ?string $step = 'askQuestion';

    public ?string $question = null;

    public function __construct(
        private TelegramUserService $telegramUserService,
        private BlockVoteService $voteService,
        private PhotoUploadFlow $photoUploadFlow,
        private ?LoggerInterface $photoLogger = null,
    ) {}

    /**
     * Same guard every multi-step conversation in this bot must carry: Nutgram routes EVERY
     * update from a user with a live conversation here, a pavilion photo included. Without
     * it the photo is swallowed and the resident is blocked for evidence they did send.
     */
    public function __invoke(Nutgram $bot, ...$parameters): mixed
    {
        if (PhotoUploadFlow::isIncomingPhoto($bot)) {
            try {
                $this->photoUploadFlow->interceptConversationPhoto(
                    $bot,
                    $this->step,
                    '📷 Ви надіслали фото — обробляємо його, створення питання скасовано. '
                        . 'Щоб запропонувати питання, відкрийте «🗳 Голосування» ще раз.',
                );
            } catch (\Throwable $e) {
                $this->photoLogger?->error('photo interception failed outright', [
                    'chat_id' => $bot->chatId(),
                    'error' => $e->getMessage(),
                ]);
            }

            return null;
        }

        return parent::__invoke($bot, ...$parameters);
    }

    public function askQuestion(Nutgram $bot): void
    {
        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;

        if (!$account instanceof Account) {
            $bot->sendMessage(
                text: "Ваш аккаунт не підтверджений ОСББ — запропонувати питання не вийде.\n"
                    . "Зв'яжіться з бухгалтером ОСББ:\n" . OsbbContacts::ACCOUNTANT_LINE,
                parse_mode: ParseMode::HTML,
            );
            $this->end();

            return;
        }

        if ($this->voteService->openQuestionOf($account) !== null) {
            $bot->sendMessage(
                text: "🗳 <b>У вас уже є відкрите питання</b>\n\n"
                    . 'Одне питання від квартири за раз — так список лишається читабельним. '
                    . 'Дочекайтесь завершення або зніміть своє в розділі «🗳 Голосування».',
                parse_mode: ParseMode::HTML,
                reply_markup: InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make(
                        '🗳 Голосування',
                        callback_data: VotingMenuCommand::MENU_CALLBACK,
                    ))
                    ->addRow(StartCommand::homeButton()),
            );
            $this->end();

            return;
        }

        $bot->sendMessage(
            text: "🗳 <b>Питання до сусідів</b>\n\n"
                . "Напишіть питання, на яке можна відповісти «так» або «ні».\n\n"
                . '<i>Наприклад: Чи ставимо лавку біля другого під’їзду? · '
                . "Чи закриваємо проїзд на ніч?</i>\n\n"
                . 'Воно одразу з’явиться в розділі «🗳 Голосування», і сусіди зможуть '
                . "відповісти.\n"
                . '<i>Розсилку всім мешканцям вмикає правління — або ваше питання зробить це '
                . 'саме, коли на нього дадуть ' . BlockVoteService::AUTO_BROADCAST_VOTES
                . ' відповідей.</i>',
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'cancel')),
        );

        $this->next('askDetails');
    }

    public function askDetails(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            if (($bot->callbackQuery()->data ?? '') === 'cancel') {
                $this->cancel($bot);
            }

            return;
        }

        $question = trim((string)$bot->message()?->text);

        if ($question === '') {
            return;
        }

        if (mb_strlen($question, 'UTF-8') < self::QUESTION_MIN) {
            $bot->sendMessage(
                text: '⚠️ Занадто коротко. Сформулюйте питання так, щоб сусід зрозумів його '
                    . 'без пояснень — принаймні ' . self::QUESTION_MIN . ' символів.',
                parse_mode: ParseMode::HTML,
            );

            return;
        }

        $this->question = mb_substr($question, 0, self::QUESTION_MAX, 'UTF-8');

        $bot->sendMessage(
            text: "📝 <b>Додайте пояснення</b> (не обов’язково)\n\n"
                . 'Чому це важливо, скільки коштує, що буде далі. Кілька речень — '
                . 'сусідам буде легше відповісти.',
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('Пропустити', callback_data: 'details:none'))
                ->addRow(InlineKeyboardButton::make('⬅️ Скасувати', callback_data: 'cancel')),
        );

        $this->next('publish');
    }

    public function publish(Nutgram $bot): void
    {
        $details = null;

        if ($bot->isCallbackQuery()) {
            $data = $bot->callbackQuery()->data ?? '';

            if ($data === 'cancel') {
                $this->cancel($bot);

                return;
            }

            if ($data !== 'details:none') {
                return;
            }
        } else {
            $text = trim((string)$bot->message()?->text);

            if ($text === '') {
                return;
            }

            $details = mb_substr($text, 0, self::DETAILS_MAX, 'UTF-8');
        }

        $user = $this->telegramUserService->getCurrentUser();
        $account = $user ? $this->telegramUserService->resolveAccount($user) : null;

        if (!$account instanceof Account || $this->question === null) {
            $bot->sendMessage(text: '⚠️ Не вдалося створити питання. Спробуйте пізніше.');
            $this->end();

            return;
        }

        $campaign = $this->voteService->openQuestion(
            $this->question,
            $details,
            null,
            $user,
            // Quiet: live in the list, no phones rung. See the class comment.
            broadcast: false,
        );

        $bot->sendMessage(
            text: "✅ <b>Питання опубліковано</b>\n\n"
                . '<b>' . self::esc((string)$campaign->getQuestion()) . "</b>\n\n"
                . 'Воно вже в розділі «🗳 Голосування» — сусіди можуть відповідати. '
                . 'Голосування триває до <b>' . $campaign->getDeadlineAt()->format('d.m.Y') . "</b>.\n\n"
                . '<i>Розсилку всім мешканцям вмикає правління. Якщо на питання дадуть '
                . BlockVoteService::AUTO_BROADCAST_VOTES . ' відповідей — бот розішле його сам.</i>',
            parse_mode: ParseMode::HTML,
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    '🗳 До голосувань',
                    callback_data: VotingMenuCommand::MENU_CALLBACK,
                ))
                ->addRow(StartCommand::homeButton()),
        );

        $this->end();
    }

    private function cancel(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: 'Скасовано — питання не створено.',
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    '🗳 Голосування',
                    callback_data: VotingMenuCommand::MENU_CALLBACK,
                ))
                ->addRow(StartCommand::homeButton()),
        );

        $this->end();
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
