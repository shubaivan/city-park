<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\TelegramUser;
use App\Repository\ScheduledSetRepository;
use App\Service\GuardService;
use PHPUnit\Framework\TestCase;

/**
 * The resident's QR names the person, not only the flat (Иван, 22.11.2026).
 *
 * Scan 31 (22.11 00:17) read «буд. 17, кв. 1» and the guard could not tell which of the
 * flat's people stood in front of him. The personal code `g-<account>-<user>-<sig16>`
 * fixes that; every flat-level code `g-<account>-<sig12>` already in somebody's phone keeps
 * working, read back with `person = null`.
 */
class ResidentQrTokenTest extends TestCase
{
    private const SECRET = 'test-secret';

    private function service(string $secret = self::SECRET): GuardService
    {
        return new GuardService($this->createMock(ScheduledSetRepository::class), '', $secret);
    }

    private static function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, $id);
    }

    private function account(int $id): Account
    {
        $account = (new Account())->setAccountNumber('2200' . $id)->setHouseNumber('17')->setApartmentNumber('1');
        self::setId($account, $id);

        return $account;
    }

    private function person(int $id, ?Account $account): TelegramUser
    {
        $user = (new TelegramUser())->setAccount($account);
        self::setId($user, $id);

        return $user;
    }

    public function testAPersonalCodeReadsBackAsTheFlatAndThePerson(): void
    {
        $flat = $this->account(7);
        $token = $this->service()->mintToken($flat, $this->person(42, $flat));

        $this->assertMatchesRegularExpression('/^g-7-42-[a-f0-9]{16}$/', $token);
        $this->assertSame(['account' => 7, 'person' => 42], $this->service()->readResidentToken($token));
        $this->assertSame(7, $this->service()->readToken($token), 'readToken() still answers the flat');
        $this->assertLessThanOrEqual(64, strlen('g-9999999999-9999999999-0123456789abcdef'), 'Telegram caps a start payload at 64');
    }

    /**
     * A code minted before 22.11.2026 — computed here from the formula that shipped on
     * 07.09.2026, not through mintToken(), so a change to that formula cannot hide itself.
     */
    public function testAnOldFlatLevelCodeStillValidates(): void
    {
        $old = 'g-7-' . substr(hash_hmac('sha256', 'guard-qr:7', self::SECRET), 0, 12);

        $this->assertSame(['account' => 7, 'person' => null], $this->service()->readResidentToken($old));
        $this->assertSame(7, $this->service()->readToken($old));
        $this->assertSame($old, $this->service()->mintToken($this->account(7)), 'without a person the old shape is minted');
        $this->assertSame($old, $this->service()->mintToken($this->account(7), new TelegramUser()), 'a person with no id is no person');
    }

    public function testAForgedOrTamperedPersonalCodeIsRefused(): void
    {
        $flat = $this->account(7);
        $token = $this->service()->mintToken($flat, $this->person(42, $flat));
        $sig = substr($token, -16);
        $guard = $this->service();

        $this->assertNull($guard->readResidentToken('g-7-43-' . $sig), 'another person on the same flat');
        $this->assertNull($guard->readResidentToken('g-8-42-' . $sig), 'the same person moved onto another flat by hand');
        $this->assertNull($guard->readResidentToken('g-7-42-0000000000000000'), 'a made-up signature');
        $this->assertNull($guard->readResidentToken('g-7-42-' . substr($sig, 0, 12)), 'a short signature');
        $this->assertNull($guard->readResidentToken('g-07-42-' . $sig), 'one code, one spelling');
        $this->assertNull($guard->readResidentToken('g-7-042-' . $sig));
        $this->assertNull($guard->readResidentToken($token . 'a'));
        $this->assertNull($guard->readResidentToken(strtoupper($token)));
        $this->assertNull($this->service('another-secret')->readResidentToken($token), 'tied to the app secret');
    }

    /** The two shapes sign different strings: neither can be turned into the other. */
    public function testTheShapesCannotBeConvertedIntoEachOther(): void
    {
        $guard = $this->service();
        $oldSig = substr($guard->mintToken($this->account(7)), -12);

        // The flat-level signature reused in the personal shape, padded to 16.
        $this->assertNull($guard->readResidentToken('g-7-42-' . $oldSig . '0000'));
        // A personal signature cut down to the old length.
        $flat = $this->account(7);
        $personal = $guard->mintToken($flat, $this->person(42, $flat));
        $this->assertNull($guard->readResidentToken('g-7-' . substr($personal, -16, 12)));
        // The guest pass prefix is not a resident code at all.
        $this->assertNull($guard->readResidentToken('p-7-' . $oldSig));
    }

    /** Unlinked, moved to another flat or gone from the bot — the personal code is dead. */
    public function testThePersonMustStillBeOnThatFlat(): void
    {
        $flat = $this->account(7);

        $this->assertTrue(GuardService::personStillOnFlat($this->person(42, $flat), 7));
        $this->assertFalse(GuardService::personStillOnFlat($this->person(42, null), 7), 'unlinked');
        $this->assertFalse(GuardService::personStillOnFlat($this->person(42, $this->account(8)), 7), 'moved to another flat');
        $this->assertFalse(GuardService::personStillOnFlat(null, 7), 'gone from the bot');
    }

    public function testThePersonLabelCarriesTheRole(): void
    {
        $person = $this->person(42, $this->account(7))->setFullName('Олена Петренко')->setRole(TelegramUser::ROLE_TENANT);

        $this->assertSame('Олена Петренко · Орендар', GuardService::personLabel($person));
        $this->assertSame('без імені · Не вказано', GuardService::personLabel($this->person(43, null)));
    }
}
