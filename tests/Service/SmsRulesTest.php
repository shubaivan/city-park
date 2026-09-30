<?php

namespace App\Tests\Service;

use App\Entity\SmsLog;
use App\Service\PhoneKey;
use App\Service\SmsSender;
use PHPUnit\Framework\TestCase;

/**
 * The rules that cost money if they are wrong.
 *
 * Every other channel in this bot is free, so a mistake there is a nuisance. Here a
 * mistake is a bill the ОСББ did not agree to, or the same resident written to twice,
 * and neither can be taken back once the message has left.
 */
class SmsRulesTest extends TestCase
{
    /**
     * A Cyrillic SMS is 70 characters, not 160.
     *
     * This is the single most expensive thing to get wrong: the text looks short, it goes
     * out as three parts, and the run costs three times what was quoted to the head of
     * the ОСББ.
     */
    public function testCyrillicFitsSeventyCharacters(): void
    {
        $this->assertSame(1, SmsSender::parts(str_repeat('а', 70)));
        $this->assertSame(2, SmsSender::parts(str_repeat('а', 71)));
        $this->assertSame(1, SmsSender::parts(str_repeat('a', 160)));
        $this->assertSame(2, SmsSender::parts(str_repeat('a', 161)));
        $this->assertSame(0, SmsSender::parts(''));
    }

    /**
     * One Ukrainian letter in an otherwise Latin text prices the whole message as
     * Cyrillic — which is the trap an «I'll just write the address in English» edit
     * walks straight into.
     */
    public function testASingleCyrillicLetterPricesTheWholeMessage(): void
    {
        $latin = str_repeat('a', 100);

        $this->assertSame(1, SmsSender::parts($latin));
        $this->assertSame(2, SmsSender::parts($latin . 'і'));
    }

    /** The text the debtors actually get has to be one SMS, with a real sum in it. */
    public function testTheDebtMessageIsOneSms(): void
    {
        foreach ([81, 1024, 5430, 16314, 123456] as $debt) {
            $text = sprintf('ОСББ Сіті Парк: борг %d грн. Деталі у боті t.me/che_city_park_bot', $debt);

            $this->assertSame(
                1,
                SmsSender::parts($text),
                sprintf('«%s» must fit one SMS (%d chars)', $text, mb_strlen($text)),
            );
        }
    }

    /** Price is parts × tariff, and a two-part message really does cost twice. */
    public function testCostFollowsTheParts(): void
    {
        $this->assertSame(0.98, SmsSender::cost(str_repeat('а', 70), 0.98));
        $this->assertSame(1.96, SmsSender::cost(str_repeat('а', 71), 0.98));
    }

    /**
     * The number that goes to the provider is international, built from the same nine
     * digits the rest of the bot recognises a person by — never from the prefix somebody
     * happened to type.
     */
    public function testEveryWrittenShapeReachesTheSameSubscriber(): void
    {
        foreach (['+380 93 272 99 51', '380932729951', '0932729951', '+380932729951'] as $shape) {
            $this->assertSame('932729951', PhoneKey::of($shape));
        }
    }

    /**
     * A foreign number is never "helpfully" turned into a Ukrainian one.
     *
     * All three of these are real rows on prod (21.09.2026). `79595221999` is a `+7 959`
     * mobile from occupied Luhansk and belongs to a resident **linked to буд. 23, кв. 47**;
     * `48796496316` is Polish. Taking the last nine digits and putting `380` in front of
     * them turns the first into `380595221999` and the second into `380796496316` — real
     * Ukrainian subscribers who are not these people, and who would have received a
     * stranger's flat number and debt.
     */
    public function testAForeignNumberIsRefusedRatherThanConverted(): void
    {
        foreach (['79595221999', '48796496316', '4555206399'] as $foreign) {
            $this->assertFalse(
                PhoneKey::isUkrainian($foreign),
                sprintf('«%s» is not a Ukrainian number and must never be sent to', $foreign),
            );
        }
    }

    /** The shapes the ОСББ's own records actually use are all accepted. */
    public function testEveryUkrainianShapeIsAccepted(): void
    {
        foreach (['+380 93 272 99 51', '380932729951', '0932729951', '932729951'] as $ours) {
            $this->assertTrue(PhoneKey::isUkrainian($ours), $ours);
        }
    }

    /** Nonsense is not a number either, and an empty field is not "some subscriber". */
    public function testRubbishIsNotAUkrainianNumber(): void
    {
        foreach (['', null, '12345', '00000000000000'] as $rubbish) {
            $this->assertFalse(PhoneKey::isUkrainian($rubbish));
        }
    }

    /** A journal row records the outcome, and a failure is as much a row as a success. */
    public function testTheJournalKeepsFailuresToo(): void
    {
        $log = new SmsLog('+380932729951', 'текст', SmsLog::PURPOSE_DEBT);

        $this->assertSame('932729951', $log->getPhoneKey());
        $this->assertSame(SmsLog::STATUS_SENT, $log->getStatus());

        $log->markFailed('недостатньо коштів');
        $this->assertTrue($log->isFailed());
        $this->assertSame('недостатньо коштів', $log->getError());

        $log->markSent('abc-123');
        $this->assertTrue($log->isSent());
        $this->assertSame('abc-123', $log->getProviderId());
        $this->assertNull($log->getError(), 'a later success must not leave the old error standing');
    }

    /** A dry run is priced and recorded, and is never mistaken for something that was sent. */
    public function testADryRunIsNotASend(): void
    {
        $log = (new SmsLog('380932729951', 'текст', SmsLog::PURPOSE_DEBT))->markDryRun();

        $this->assertFalse($log->isSent());
        $this->assertFalse($log->isFailed());
        $this->assertSame(SmsLog::STATUS_DRY_RUN, $log->getStatus());
    }

    /**
     * An error longer than the column must not throw on its way into the journal.
     *
     * The rows worth having are the failures, and a provider that answers with a wall of
     * text is exactly when one is being written.
     */
    public function testALongProviderErrorStillFitsTheJournal(): void
    {
        $log = (new SmsLog('380932729951', 'текст', SmsLog::PURPOSE_DEBT))->markFailed(str_repeat('щось пішло не так ', 100));

        $this->assertLessThanOrEqual(255, mb_strlen((string)$log->getError()));
    }
}
