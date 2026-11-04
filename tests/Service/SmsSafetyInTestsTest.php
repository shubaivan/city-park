<?php

namespace App\Tests\Service;

use App\Entity\SmsLog;
use App\Repository\SmsLogRepository;
use App\Service\SmsSender;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The floor under every other SMS test: this environment cannot pay for a message.
 */
class SmsSafetyInTestsTest extends KernelTestCase
{
    /** No token, so SmsSender refuses before any HTTP call. A token here fails loudly. */
    public function testTheTestEnvironmentHasNoTurboSmsToken(): void
    {
        self::bootKernel();

        $this->assertFalse(self::getContainer()->get(SmsSender::class)->isConfigured());
    }

    /** A send whose answer never came must block a repeat, or the retry is a paid duplicate. */
    public function testTheSameDayGuardCountsASendWithNoAnswer(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn(new ClassMetadata(SmsLog::class));

        $dql = null;
        // Query is final and there is no database here: the DQL is what is being pinned.
        $em->method('createQuery')->willReturnCallback(static function (string $query) use (&$dql): never {
            $dql = $query;

            throw new \DomainException('stop here');
        });
        $em->method('createQueryBuilder')->willReturnCallback(static fn () => new \Doctrine\ORM\QueryBuilder($em));

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        try {
            (new SmsLogRepository($registry))->alreadySentToday('671111111', SmsLog::PURPOSE_DEBT);
        } catch (\DomainException) {
        }

        $this->assertStringContainsString('s.status = :sent OR s.status = :sending OR (s.status = :failed AND s.error LIKE :unknown)', (string)$dql);
    }
}
