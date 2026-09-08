<?php

namespace App\Tests\Telegram;

use App\Telegram\ServiceOffer\Command\ServicePublish;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A number attached from the phone book must be accepted like a typed one.
 *
 * That is where somebody's electrician's number actually lives — saved, not memorised —
 * and 📎 → «Контакт» is the one path that cannot mistype a digit. Telegram offers no way
 * to *open* that picker from a button (`request_contact` returns the user's own number and
 * nothing else, which is what the «Мій номер» button already is), but it does deliver
 * whatever the person attaches.
 *
 * Before this, attaching a contact answered «⚠️ Не схоже на український номер»: the message
 * carries a `contact` and no `text`, so the step read null and refused. From the outside
 * that is the bot ignoring the attachment.
 */
class ServiceContactCardTest extends KernelTestCase
{
    private const USER_ID = 485598262;

    public function testAnAttachedContactFillsInTheNumber(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // The preview the step renders on success asks who is publishing. Mocked rather
        // than seeded: this test is about reading a contact card, and a database would
        // make it the only one in the suite that needs one.
        $this->stubCurrentUser($container);

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate($container);
        require $container->getParameter('kernel.project_dir') . '/config/telegram.php';

        $conversation = $container->get(ServicePublish::class);
        $conversation->title = 'Електрик';
        (new \ReflectionProperty(Conversation::class, 'step'))->setValue($conversation, 'confirm');
        $bot->stepConversation($conversation, self::USER_ID, self::USER_ID);

        $bot->processUpdate($this->contactUpdate('+380501234567'));

        $stored = $bot->getContainer()
            ->get(\SergiX44\Nutgram\Cache\ConversationCache::class)
            ->get(self::USER_ID, self::USER_ID, null);

        $this->assertInstanceOf(ServicePublish::class, $stored, 'the conversation must survive the contact');
        $this->assertSame(
            '+380 50 123 45 67',
            $stored->phone,
            'a contact card must fill in the number, normalised the same way a typed one is',
        );
    }

    /** A foreign number in the card is refused, and says which half was wrong. */
    public function testAForeignContactIsRefusedWithoutLosingTheDraft(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate($container);
        require $container->getParameter('kernel.project_dir') . '/config/telegram.php';

        $conversation = $container->get(ServicePublish::class);
        $conversation->title = 'Електрик';
        (new \ReflectionProperty(Conversation::class, 'step'))->setValue($conversation, 'confirm');
        $bot->stepConversation($conversation, self::USER_ID, self::USER_ID);

        $bot->processUpdate($this->contactUpdate('+15551234567'));

        $stored = $bot->getContainer()
            ->get(\SergiX44\Nutgram\Cache\ConversationCache::class)
            ->get(self::USER_ID, self::USER_ID, null);

        $this->assertInstanceOf(ServicePublish::class, $stored);
        $this->assertNull($stored->phone);
        $this->assertSame('Електрик', $stored->title, 'the draft must survive a refused number');
    }

    private function stubCurrentUser(object $container): void
    {
        $account = (new \App\Entity\Account())
            ->setAccountNumber('2-1-0-085')
            ->setApartmentNumber('85')
            ->setHouseNumber('19')
            ->setStreet('Козацька');

        $user = (new \App\Entity\TelegramUser())->setPhoneNumber('380633022666');
        $user->setAccount($account);

        $users = $this->createMock(\App\Service\TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn($user);
        $users->method('resolveAccount')->willReturn($account);

        $container->set(\App\Service\TelegramUserService::class, $users);
    }

    private function contactUpdate(string $phone): Update
    {
        return Update::fromArray([
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'date' => 1755000000,
                'from' => ['id' => self::USER_ID, 'is_bot' => false, 'first_name' => 'Resident'],
                'chat' => ['id' => self::USER_ID, 'type' => 'private', 'first_name' => 'Resident'],
                'contact' => ['phone_number' => $phone, 'first_name' => 'Вітя електрик'],
            ],
        ]);
    }
}
