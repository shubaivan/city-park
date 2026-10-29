<?php

namespace App\Tests\Telegram;

use App\Entity\Account;
use App\Entity\RentalListing;
use App\Service\RentalListingService;
use App\Telegram\Rental\Command\RentalPublish;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The object of a listing is typed by its author (29.10.2026): a комора billed to a wife,
 * a паркомісце on its own рахунок — none of them could be advertised while the object was
 * read off the author's Account. Driven through the real conversation, step by step.
 */
class RentalTypedObjectTest extends KernelTestCase
{
    private const USER_ID = 537530415;

    public function testAStorageIsTypedAndSkipsTheFlatQuestions(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->stubCurrentUser($container);

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate($container);
        require $container->getParameter('kernel.project_dir') . '/config/telegram.php';

        $conversation = $container->get(RentalPublish::class);
        (new \ReflectionProperty(Conversation::class, 'step'))->setValue($conversation, 'askHouse');
        $bot->stepConversation($conversation, self::USER_ID, self::USER_ID);

        $bot->processUpdate($this->tap('type:' . Account::UNIT_STORAGE));
        $bot->processUpdate($this->tap('house:21'));
        $bot->processUpdate($this->text('168'));
        $bot->processUpdate($this->text('7'));

        $stored = $this->stored($bot);
        $this->assertSame(Account::UNIT_STORAGE, $stored->objectType);
        $this->assertSame('21', $stored->objectHouse);
        $this->assertSame('168', $stored->objectNumber);
        $this->assertSame(7, $stored->entrance);
        $this->assertNull($stored->floor, 'a комора is not asked for a floor');
        $this->assertNull($stored->rooms, 'nor for rooms');

        $bot->processUpdate($this->text('2000'));
        $this->assertSame(2000, $this->stored($bot)->price, 'straight from the entrance to the price');
    }

    /** No bounds on the price: the owner decides (Иван, 29.10.2026). */
    public function testAnyPriceIsTheOwnersCall(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->stubCurrentUser($container);

        $bot = FakeNutgram::instance();
        $bot->getContainer()->delegate($container);
        require $container->getParameter('kernel.project_dir') . '/config/telegram.php';

        $conversation = $container->get(RentalPublish::class);
        $conversation->deal = RentalListing::DEAL_SALE;
        $conversation->objectType = Account::UNIT_APARTMENT;
        (new \ReflectionProperty(Conversation::class, 'step'))->setValue($conversation, 'askDescription');
        $bot->stepConversation($conversation, self::USER_ID, self::USER_ID);

        $bot->processUpdate($this->text('2 400 000'));

        $this->assertSame(2400000, $this->stored($bot)->price);
    }

    public function testATypedListingNamesItsObjectAndWhoPostedIt(): void
    {
        $author = (new Account())->setAccountNumber('320090')->setApartmentNumber('90')->setHouseNumber('21');

        $listing = (new RentalListing())
            ->setAccount($author)
            ->setObject(Account::UNIT_STORAGE, '21', '168', 7, null);

        $this->assertSame('буд. 21, комірчина 168', RentalListingService::objectPlain($listing));
        $this->assertSame(["буд. 21, комірчина 168", "під'їзд 7"], RentalListingService::head($listing));
        $this->assertSame('👤 Розмістив: буд. 21, кв. 90', RentalListingService::postedBy($listing));

        // The author's own flat needs no «розмістив» line — it would say the same thing twice.
        $own = (new RentalListing())->setAccount($author)->setObject(Account::UNIT_APARTMENT, '21', '90');
        $this->assertNull(RentalListingService::postedBy($own));

        // A listing from before objects were typed still reads its Account.
        $legacy = (new RentalListing())->setAccount($author);
        $this->assertSame('буд. 21, кв. 90', RentalListingService::objectPlain($legacy));
    }

    /** A republish of the same object replaces it; another object is a second listing. */
    public function testSameObjectIsMatchedByKindBuildingAndNumber(): void
    {
        $listing = (new RentalListing())->setObject(Account::UNIT_STORAGE, '21', '168');

        $this->assertTrue($listing->isSameObject(Account::UNIT_STORAGE, '21', '168'));
        $this->assertFalse($listing->isSameObject(Account::UNIT_APARTMENT, '21', '168'));
        $this->assertFalse($listing->isSameObject(Account::UNIT_STORAGE, '19', '168'));
    }

    private function stored(FakeNutgram $bot): RentalPublish
    {
        $stored = $bot->getContainer()
            ->get(\SergiX44\Nutgram\Cache\ConversationCache::class)
            ->get(self::USER_ID, self::USER_ID, null);

        $this->assertInstanceOf(RentalPublish::class, $stored, 'the conversation must still be live');

        return $stored;
    }

    private function stubCurrentUser(object $container): void
    {
        $account = (new Account())
            ->setAccountNumber('320090')
            ->setApartmentNumber('90')
            ->setHouseNumber('21')
            ->setStreet('Козацька');

        $user = (new \App\Entity\TelegramUser())->setPhoneNumber('380933990777');
        $user->setAccount($account);

        $users = $this->createMock(\App\Service\TelegramUserService::class);
        $users->method('getCurrentUser')->willReturn($user);
        $users->method('resolveAccount')->willReturn($account);

        $container->set(\App\Service\TelegramUserService::class, $users);
    }

    private function text(string $text): Update
    {
        return Update::fromArray([
            'update_id' => random_int(1, 1_000_000),
            'message' => [
                'message_id' => 10,
                'date' => 1755000000,
                'from' => ['id' => self::USER_ID, 'is_bot' => false, 'first_name' => 'Resident'],
                'chat' => ['id' => self::USER_ID, 'type' => 'private', 'first_name' => 'Resident'],
                'text' => $text,
            ],
        ]);
    }

    private function tap(string $data): Update
    {
        return Update::fromArray([
            'update_id' => random_int(1, 1_000_000),
            'callback_query' => [
                'id' => (string)random_int(1, 1_000_000),
                'from' => ['id' => self::USER_ID, 'is_bot' => false, 'first_name' => 'Resident'],
                'chat_instance' => '1',
                'data' => $data,
                'message' => [
                    'message_id' => 11,
                    'date' => 1755000000,
                    'chat' => ['id' => self::USER_ID, 'type' => 'private', 'first_name' => 'Resident'],
                    'text' => 'menu',
                ],
            ],
        ]);
    }
}
