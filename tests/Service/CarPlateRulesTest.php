<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\CarPlate;
use App\Entity\TelegramUser;
use App\Repository\CarPlateRepository;
use App\Repository\ScheduledSetRepository;
use App\Service\CarPlateAnnouncer;
use App\Service\CarPlateService;
use App\Service\GuardService;
use App\Service\PlateKey;
use App\Telegram\CarPlate\Command\CarPlateCheck;
use App\Telegram\CarPlate\Command\CarPlateCommand;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The house's list of cars, and the rules that keep it a list worth having.
 *
 * Its value is one lookup — «чиє це авто» at the gate — and every rule here is about that
 * lookup being right: one spelling per plate whatever alphabet it was typed in, one object
 * per plate, and an answer that cannot be mistaken for «чуже» while the list is still filling.
 */
class CarPlateRulesTest extends TestCase
{
    private function account(int $id, string $flat = '85'): Account
    {
        $account = (new Account())
            ->setAccountNumber('22' . $id)
            ->setApartmentNumber($flat)
            ->setHouseNumber('19')
            ->setStreet('Козацька');
        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }

    private function plateOn(Account $account, string $key): CarPlate
    {
        return (new CarPlate())->setAccount($account)->setPlate($key);
    }

    /**
     * @param CarPlate[] $onAccount
     */
    private function service(?CarPlate $existing = null, array $onAccount = [], ?EntityManagerInterface $em = null, string $guardIds = ''): CarPlateService
    {
        $repo = $this->createMock(CarPlateRepository::class);
        $repo->method('findByKey')->willReturn($existing);
        $repo->method('forAccount')->willReturn($onAccount);

        return new CarPlateService(
            $repo,
            $em ?? $this->createMock(EntityManagerInterface::class),
            new GuardService($this->createMock(ScheduledSetRepository::class), $guardIds),
        );
    }

    /**
     * «СА 1234 АВ» typed in Cyrillic, in Latin, mixed, lower-case, with dashes — one car.
     * Compared as typed they would be five, and the guard's lookup would find none of them.
     */
    public function testEverySpellingOfOnePlateIsOneKey(): void
    {
        foreach (['СА 1234 АВ', 'CA 1234 AB', 'Cа1234Ab', 'са-1234-ав', ' CA1234AB '] as $typed) {
            $this->assertSame('CA1234AB', PlateKey::of($typed), sprintf('«%s»', $typed));
        }
    }

    /** All twelve plate letters fold onto their Latin twins. */
    public function testEveryPlateLetterHasItsLatinTwin(): void
    {
        $this->assertSame('AB1234CE', PlateKey::of('АВ1234СЕ'));
        $this->assertSame('HI1234KM', PlateKey::of('НІ1234КМ'));
        $this->assertSame('OP1234TX', PlateKey::of('ОР1234ТХ'));
    }

    /**
     * A Cyrillic letter that is not on plates is refused, never stripped: dropping it would
     * turn «СЖ 1234» into «C1234» — another car, matched with full confidence.
     */
    public function testALetterOffThePlateAlphabetIsRefusedNotDropped(): void
    {
        $this->assertSame('', PlateKey::of('СЖ 1234 АВ'));
        $this->assertSame('', PlateKey::of('Тойота біла'));
    }

    public function testWhatIsNotAPlateIsRefused(): void
    {
        foreach (['', '12', 'ABCDEFGH', 'CA 1234 AB 1234 XX', 'CA1234AB!'] as $typed) {
            $this->assertSame('', PlateKey::of($typed), sprintf('«%s»', $typed));
        }
    }

    /**
     * Electric, foreign and individual plates get on the list. The strict «СА 1234 АВ» shape
     * lasted an afternoon: two residents could not add their cars, one because it is electric.
     */
    public function testElectricForeignAndIndividualPlatesPass(): void
    {
        $this->assertSame('CA1234YZ', PlateKey::of('CA 1234 YZ'));
        $this->assertSame('CA1234DZ', PlateKey::of('ca1234dz'));
        $this->assertSame('WX12345', PlateKey::of('WX 12345'));
        $this->assertSame('KYIV777', PlateKey::of('KYIV 777'));
        $this->assertSame('WX12345', PlateKey::display('WX12345'));

        // «У» typed on a Cyrillic keyboard for the Y on the plate.
        $this->assertSame('CA1234YZ', PlateKey::of('СА 1234 УZ'));

        foreach (['CA0801KK', 'CA0800KP', 'CA5410CA'] as $real) {
            $this->assertSame($real, PlateKey::of($real));
        }
    }

    /** The refusal says what to change, naming the letter when it is a letter. */
    public function testARefusalNamesWhatIsWrong(): void
    {
        $this->assertStringContainsString('«Ж»', (string)PlateKey::problem('СЖ 1234 АВ'));
        $this->assertNotNull(PlateKey::problem('1234'));
        $this->assertNotNull(PlateKey::problem('Тойота'));
        $this->assertNull(PlateKey::problem('СА 1234 АВ'));

        // Two cars in one message: what three residents out of three sent on day one.
        $this->assertSame(PlateKey::ONE_AT_A_TIME, PlateKey::problem("КА0012ХМ\nКА0717ІК"));
        $this->assertSame(PlateKey::ONE_AT_A_TIME, PlateKey::problem("КА 0012 ХМ\nКА  0717 ІК"));
        $this->assertSame(PlateKey::ONE_AT_A_TIME, PlateKey::problem('СА9044КС, СА6606YB'));
        $this->assertSame('', PlateKey::of("КА0012ХМ\nКА0717ІК"));
        // On one line it cannot be told apart from a long number, but the hint is still there.
        $this->assertStringContainsString('по одному', (string)PlateKey::problem('КА 0012 ХМ КА 0717 ІК'));
        // A number written over two lines is still one number.
        $this->assertNull(PlateKey::problem("СА 1234\nАВ"));
    }

    /** Where the car stands: optional, free text, and «немає» means none. */
    public function testTheParkingSpotIsOptionalAndFreeText(): void
    {
        $plate = $this->plateOn($this->account(1), 'CA1234AB');
        $service = $this->service();

        $service->setSpot($plate, '  45,   орендоване ');
        $this->assertSame('45, орендоване', $plate->getParkingSpot());

        $service->setSpot($plate, 'немає');
        $this->assertNull($plate->getParkingSpot());

        $service->setSpot($plate, str_repeat('7', 100));
        $this->assertSame(CarPlate::SPOT_MAX_LENGTH, mb_strlen((string)$plate->getParkingSpot()));
    }

    public function testTheGuardSeesTheSpotWhenThereIsOne(): void
    {
        $this->assertStringContainsString('🅿️ Паркомісце: 138', CarPlateCheck::verdict('CA1234AB', 'буд. 19, кв. 85', '138'));
        $this->assertStringNotContainsString('🅿️', CarPlateCheck::verdict('CA1234AB', 'буд. 19, кв. 85'));
    }

    public function testTheStandardShapeIsShownWithItsSpaces(): void
    {
        $this->assertSame('CA 1234 AB', PlateKey::display('CA1234AB'));
    }

    public function testANewPlateIsAdded(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->callback(
            static fn (CarPlate $p): bool => $p->getPlate() === 'CA1234AB',
        ));

        $this->assertSame(CarPlateService::ADDED, $this->service(em: $em)->add($this->account(1), null, 'са 1234 ав'));
    }

    /**
     * The same plate on another flat is refused — and the refusal names nothing. Saying
     * «уже за буд. 19, кв. 50» would make «add» a lookup anybody in the bot could run on
     * any car in the yard.
     */
    public function testAPlateOnAnotherFlatIsRefusedWithoutSayingWhichFlat(): void
    {
        $service = $this->service(existing: $this->plateOn($this->account(2, '50'), 'CA1234AB'));

        $this->assertSame(CarPlateService::TAKEN, $service->add($this->account(1), null, 'CA 1234 AB'));

        $source = (string)file_get_contents(__DIR__ . '/../../src/Telegram/CarPlate/Command/CarPlateAdd.php');
        preg_match('/CarPlateService::TAKEN => sprintf\((.*?)\),\n/s', $source, $m);
        $this->assertNotEmpty($m, 'the TAKEN answer is where it was');
        $this->assertStringNotContainsString('getPlaceLabel', $m[1]);
        $this->assertStringNotContainsString('getAccount', $m[1]);
    }

    public function testTheSamePlateTwiceOnOneFlatIsNotAnError(): void
    {
        $account = $this->account(1);
        $service = $this->service(existing: $this->plateOn($account, 'CA1234AB'));

        $this->assertSame(CarPlateService::ALREADY_OURS, $service->add($account, null, 'CA1234AB'));
    }

    public function testAFullFlatTakesNoMore(): void
    {
        $account = $this->account(1);
        $five = array_map(fn (int $i): CarPlate => $this->plateOn($account, 'AA000' . $i . 'AA'), range(1, CarPlate::MAX_PER_ACCOUNT));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $service = $this->service(onAccount: $five, em: $em);

        $this->assertFalse($service->mayAdd($account));
        $this->assertSame(CarPlateService::FULL, $service->add($account, null, 'BC 7777 BC'));
    }

    /** Somebody with no flat in the bot has nowhere to write a car down. */
    public function testNoFlatNoPlates(): void
    {
        $this->assertFalse($this->service()->mayAdd(null));
    }

    /** One household cannot delete another's plate by sending its id. */
    public function testAnotherFlatsPlateIsNotYours(): void
    {
        $repo = $this->createMock(CarPlateRepository::class);
        $repo->method('find')->willReturn($this->plateOn($this->account(2), 'CA1234AB'));

        $service = new CarPlateService(
            $repo,
            $this->createMock(EntityManagerInterface::class),
            new GuardService($this->createMock(ScheduledSetRepository::class)),
        );

        $this->assertNull($service->own($this->account(1), 7));
    }

    /**
     * The lookup is the guard's alone in the bot, and an empty guard list means nobody —
     * the rule every flag in this codebase follows.
     */
    public function testOnlyTheGuardMayLookAPlateUp(): void
    {
        $guard = (new TelegramUser())->setTelegramId('8889997372');
        $resident = (new TelegramUser())->setTelegramId('111');

        $this->assertTrue($this->service(guardIds: '8889997372')->mayLookUp($guard));
        $this->assertFalse($this->service(guardIds: '8889997372')->mayLookUp($resident));
        $this->assertFalse($this->service()->mayLookUp($guard));
        $this->assertFalse($this->service(guardIds: '8889997372')->mayLookUp(null));
    }

    public function testAKnownPlateNamesTheFlat(): void
    {
        $verdict = CarPlateCheck::verdict('CA1234AB', 'буд. 19, кв. 85');

        $this->assertStringContainsString('✅', $verdict);
        $this->assertStringContainsString('CA 1234 AB', $verdict);
        $this->assertStringContainsString('буд. 19, кв. 85', $verdict);
    }

    /**
     * Writing a number down is voluntary, so on the first day almost every resident's car
     * is absent — and a guard who reads «немає» as «чуже» turns a neighbour away at his own
     * gate. The answer has to say so every time.
     */
    public function testAnUnknownPlateIsNotCalledForeign(): void
    {
        $verdict = CarPlateCheck::verdict('CA1234AB', null);

        $this->assertStringContainsString('немає в переліку', $verdict);
        $this->assertStringContainsString('не означає, що авто чуже', $verdict);
        $this->assertStringContainsString('за бажанням', $verdict);
    }

    /** The section says it is optional before it says anything else. */
    public function testTheSectionSaysItIsOptional(): void
    {
        $intro = CarPlateCommand::intro();

        $this->assertStringContainsString("За бажанням, не обов'язково", $intro);
        $this->assertLessThan(120, mb_strpos($intro, 'За бажанням'), 'it leads, it is not a footnote');
        $this->assertStringContainsString('Сусіди їх не бачать', $intro);
    }

    /**
     * The chat hears that a household joined the list, and how many have — never the
     * number. A plate in a chat of 457 is the lookup the list refuses to be.
     */
    public function testTheChatPostNamesTheFlatAndNeverThePlate(): void
    {
        $post = CarPlateAnnouncer::post('буд. 19, кв. 85', 3, 4);

        $this->assertStringContainsString('Буд. 19, кв. 85', $post);
        $this->assertStringContainsString('дані про авто внесли <b>3 квартири</b>, у переліку <b>4 автомобілі</b>', $post);
        $this->assertStringContainsString('<b>5 автомобілів</b>', CarPlateAnnouncer::post('буд. 17, кв. 78', 5, 5));
        $this->assertStringContainsString('<b>1 автомобіль</b>', CarPlateAnnouncer::post('буд. 17, кв. 78', 1, 1));
        $this->assertDoesNotMatchRegularExpression('/[A-ZА-Я]{2}\s?\d{4}\s?[A-ZА-Я]{2}/u', $post);
        $this->assertStringContainsString("не обов'язково", $post);

        $this->assertStringContainsString('1 квартира', CarPlateAnnouncer::post('буд. 17, кв. 78', 1, 1));
        $this->assertStringContainsString('11 квартир', CarPlateAnnouncer::post('буд. 17, кв. 78', 11, 11));
        $this->assertStringContainsString('21 квартира', CarPlateAnnouncer::post('буд. 17, кв. 78', 21, 21));
        $this->assertStringContainsString('5 квартир', CarPlateAnnouncer::post('буд. 17, кв. 78', 5, 5));
    }
}
