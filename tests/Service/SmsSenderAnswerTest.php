<?php

namespace App\Tests\Service;

use App\Entity\SmsLog;
use App\Repository\SmsLogRepository;
use App\Service\SmsSender;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What a TurboSMS answer means for the journal, by the code table at turbosms.ua/api.html.
 * Sent has an id, refused left nothing behind, and unknown may have been billed. Answered from
 * here: nothing leaves this process.
 */
class SmsSenderAnswerTest extends TestCase
{
    private const PHONE = '380671111111';

    /** @dataProvider answers */
    public function testTheAnswerForTheRecipientDecides(array $body, string $expected, ?string $id, ?string $error): void
    {
        $log = $this->sendAnswered($body);

        $this->assertSame($expected === 'sent' ? SmsLog::STATUS_SENT : SmsLog::STATUS_FAILED, $log->getStatus());
        $this->assertSame($id, $log->getProviderId());

        if ($expected === 'unknown') {
            $this->assertStringStartsWith(SmsSender::OUTCOME_UNKNOWN, (string)$log->getError());
        }
        if ($expected === 'refused') {
            $this->assertStringStartsNotWith(SmsSender::OUTCOME_UNKNOWN, (string)$log->getError());
        }
        if ($error !== null) {
            $this->assertStringContainsString($error, (string)$log->getError());
        }
    }

    public static function answers(): iterable
    {
        $entry = static fn (?string $id, int $code, string $status, string $phone = self::PHONE): array => [
            'phone' => $phone, 'response_code' => $code, 'message_id' => $id, 'response_status' => $status,
        ];
        $body = static fn (int $code, string $status, mixed $result): array => [
            'response_code' => $code, 'response_status' => $status, 'response_result' => $result,
        ];

        yield 'accepted' => [$body(800, 'SUCCESS_MESSAGE_ACCEPTED', [$entry('id-1', 0, 'OK')]), 'sent', 'id-1', null];
        yield 'sent at once' => [$body(801, 'SUCCESS_MESSAGE_SENT', [$entry('id-1', 0, 'OK')]), 'sent', 'id-1', null];
        yield 'partial, this one accepted' => [
            $body(802, 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED', [$entry(null, 406, 'NOT_ALLOWED_RECIPIENT_COUNTRY', '380672222222'), $entry('id-1', 0, 'OK')]),
            'sent', 'id-1', null,
        ];
        yield 'partial, this one refused' => [
            $body(802, 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED', [$entry(null, 406, 'NOT_ALLOWED_RECIPIENT_COUNTRY')]),
            'refused', null, 'NOT_ALLOWED_RECIPIENT_COUNTRY (406)',
        ];
        yield 'partial, in the stoplist' => [
            $body(803, 'SUCCESS_MESSAGE_PARTIAL_SENT', [$entry(null, 404, 'NOT_ALLOWED_NUMBER_STOPLIST')]),
            'refused', null, 'NOT_ALLOWED_NUMBER_STOPLIST (404)',
        ];
        yield 'number written differently' => [
            $body(802, 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED', [$entry('id-2', 0, 'OK', '0672222222'), $entry('id-1', 0, 'OK', '0671111111')]),
            'sent', 'id-1', null,
        ];
        yield 'a repeated sequence_id, the first one was accepted' => [
            $body(507, 'FAILED_DUPLICATE_REQUEST', [$entry('id-1', 0, 'OK')]), 'sent', 'id-1', null,
        ];
        yield 'a repeated sequence_id, the first one was refused' => [
            $body(507, 'FAILED_DUPLICATE_REQUEST', [$entry(null, 404, 'NOT_ALLOWED_NUMBER_STOPLIST')]),
            'refused', null, 'NOT_ALLOWED_NUMBER_STOPLIST (404)',
        ];
        yield 'accepted with an empty result' => [$body(800, 'SUCCESS_MESSAGE_ACCEPTED', []), 'unknown', null, 'не дав id'];
        yield 'accepted with no result' => [$body(800, 'SUCCESS_MESSAGE_ACCEPTED', null), 'unknown', null, 'не дав id'];
        yield 'ok for the recipient, null id' => [$body(800, 'SUCCESS_MESSAGE_ACCEPTED', [$entry(null, 0, 'OK')]), 'unknown', null, 'не дав id'];
        yield 'an id beside a refusal' => [$body(800, 'SUCCESS_MESSAGE_ACCEPTED', [$entry('id-1', 406, 'NOT_ALLOWED_RECIPIENT_COUNTRY')]), 'unknown', null, 'є id'];
        yield 'nobody in the result is them' => [
            $body(802, 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED', [$entry('a', 0, 'OK', '380672222222'), $entry('b', 0, 'OK', '380673333333')]),
            'unknown', null, 'не дав id',
        ];
        yield 'no code at all' => [['something' => 'else'], 'unknown', null, 'без коду'];
        yield 'fatal error' => [$body(9001, 'FATAL_ERROR', null), 'unknown', null, 'FATAL_ERROR (9001)'];
        yield 'result it could not encode' => [$body(500, 'FAILED_CONVERT_RESULT2JSON', null), 'unknown', null, '(500)'];
        yield 'fatal error for the recipient' => [$body(802, 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED', [$entry(null, 9001, 'FATAL_ERROR')]), 'unknown', null, 'FATAL_ERROR (9001)'];
        yield 'unencodable for the recipient' => [$body(802, 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED', [$entry(null, 500, 'FAILED_CONVERT_RESULT2JSON')]), 'unknown', null, '(500)'];
        yield 'xml for the recipient' => [$body(802, 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED', [$entry(null, 501, 'FAILED_CONVERT_RESULT2XML')]), 'unknown', null, '(501)'];
        yield 'could not send' => [$body(503, 'FAILED_SMS_SEND', null), 'unknown', null, 'FAILED_SMS_SEND (503)'];
        yield 'could not send, viber' => [$body(504, 'FAILED_VIBER_SEND', null), 'unknown', null, '(504)'];
        yield 'another method\'s success' => [$body(804, 'SUCCESS_PROCESSED', null), 'unknown', null, '(804)'];
        yield 'partial of another method' => [$body(805, 'SUCCESS_PARTIAL_PROCESSED', null), 'unknown', null, '(805)'];
        yield 'a code the table does not have' => [$body(899, 'WHO_KNOWS', null), 'unknown', null, '(899)'];
        yield 'pong' => [$body(1, 'PONG', null), 'unknown', null, '(1)'];
        yield 'a code the table does not have, for the recipient' => [$body(802, 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED', [$entry(null, 7777, 'WHO_KNOWS')]), 'unknown', null, '(7777)'];
        yield 'already in this mailing' => [$body(802, 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED', [$entry(null, 407, 'NOT_ALLOWED_RECIPIENT_DUPLICATE')]), 'unknown', null, '(407)'];
        yield 'an identical message from the last 90 seconds' => [$body(427, 'NOT_ALLOWED_MESSAGE_DUPLICATE', null), 'unknown', null, 'NOT_ALLOWED_MESSAGE_DUPLICATE (427)'];
        yield 'the same, for the recipient' => [$body(802, 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED', [$entry(null, 427, 'NOT_ALLOWED_MESSAGE_DUPLICATE')]), 'unknown', null, 'вже створено'];
        yield 'too many to this recipient' => [$body(428, 'NOT_ALLOWED_MESSAGES_NUMBER_LIMIT', null), 'refused', null, '(428)'];
        yield 'a limit of this connection' => [$body(436, 'NOT_ALLOWED_SEND_LIMIT', null), 'refused', null, '(436)'];
        yield 'no parallel sends' => [$body(438, 'NOT_ALLOWED_CONNECTIONS_LIMIT', null), 'refused', null, '(438)'];
        yield 'a sender not active' => [$body(401, 'NOT_ALLOWED_MESSAGE_SENDER_NOT_ACTIVE', null), 'refused', null, '(401)'];
        yield 'a bad number' => [$body(802, 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED', [$entry(null, 305, 'INVALID_RECIPIENT')]), 'refused', null, '(305)'];
        yield 'no money' => [$body(203, 'REQUIRED_BALANCE', null), 'refused', null, 'REQUIRED_BALANCE (203)'];
        yield 'the whole request refused' => [$body(105, 'REQUIRED_AUTH', []), 'refused', null, 'REQUIRED_AUTH (105)'];
    }

    /** The same-day guard reads this prefix, so an unknown cannot go out a second time. */
    public function testAnAcceptedAnswerWithNoIdIsGuardedLikeASentOne(): void
    {
        $log = $this->sendAnswered(['response_code' => 800, 'response_status' => 'SUCCESS_MESSAGE_ACCEPTED', 'response_result' => []]);

        $this->assertTrue($log->isFailed());
        $this->assertStringStartsWith(SmsSender::OUTCOME_UNKNOWN, (string)$log->getError());
    }

    private function sendAnswered(array $body): SmsLog
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode($body)), 'https://api.turbosms.ua');

        return (new SmsSender(
            $http,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(SmsLogRepository::class),
            new NullLogger(),
            'mock-token',
            'CityPark',
        ))->send(self::PHONE, 'Буд.27 кв.63: борг 5430 грн. Просимо сплатити.', SmsLog::PURPOSE_DEBT, new \App\Entity\Account());
    }
}
