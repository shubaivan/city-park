<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\GuestPass;
use App\Entity\QrScan;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

/**
 * The scan log, rendered.
 *
 * It is the page opened *after* something happened in the yard, so the two things it must
 * never do are draw a result as the wrong one and leave a live pass out. Both have
 * precedent: /admin/links spent a week rendering votes as complaints because its template
 * ended in an unguarded `else`.
 */
class AdminScansPageTest extends KernelTestCase
{
    private function render(array $entries, array $passes = []): string
    {
        self::bootKernel();

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(new InMemoryUser('alina', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']),
        );

        return self::getContainer()->get(Environment::class)->render('admin/scans.html.twig', [
            'entries' => $entries,
            'passes' => $passes,
            'total' => count($entries),
            'shown' => 300,
            'today' => (new \DateTime('now', new \DateTimeZone('Europe/Kyiv')))->format('Y-m-d'),
        ]);
    }

    private function scan(string $kind, string $result): QrScan
    {
        return (new QrScan())
            ->setKind($kind)
            ->setResult($result)
            ->setScannedByLabel('Ніка (буд. 19, кв. 85)')
            ->setSubjectLabel('буд. 19, кв. 12')
            ->setGuestPassId($kind === QrScan::KIND_GUEST ? 4 : null);
    }

    public function testItNamesWhoScannedWhomAndWhatTheBotAnswered(): void
    {
        $html = $this->render([$this->scan(QrScan::KIND_GUEST, QrScan::RESULT_OK)]);

        $this->assertStringContainsString('Ніка (буд. 19, кв. 85)', $html);
        $this->assertStringContainsString('буд. 19, кв. 12', $html);
        $this->assertStringContainsString('дійсний', $html);
        $this->assertStringContainsString('пропуск', $html);
    }

    /**
     * Every result the code can write has a word on the page.
     *
     * An unlisted one falling into a neutral branch is the *designed* behaviour; falling
     * into another result's branch is the /admin/links failure, where a kind nobody had
     * added was drawn as a complaint and linked to the wrong register for a week.
     */
    public function testEveryResultIsNamed(): void
    {
        $template = (string)file_get_contents(__DIR__ . '/../../templates/admin/scans.html.twig');

        foreach ([
            QrScan::RESULT_OK,
            QrScan::RESULT_NO_BOOKING,
            QrScan::RESULT_NOT_ACTIVE,
            QrScan::RESULT_REVOKED,
            QrScan::RESULT_UNKNOWN,
        ] as $result) {
            $this->assertStringContainsString(
                "result == '" . $result . "'",
                $template,
                sprintf('«%s» would render as whatever the last branch happens to be', $result),
            );
        }
    }

    /** The live passes are on the page: «які пропуски зараз ходять по ЖК». */
    public function testLivePassesAreListedWithTheirDay(): void
    {
        $account = (new Account())
            ->setAccountNumber('220085')
            ->setApartmentNumber('85')
            ->setHouseNumber('19')
            ->setStreet('Козацька');

        $pass = (new GuestPass())->setAccount($account)->setLabel('Бригада, ремонт');
        $pass->setActiveOn(new \DateTime('now', new \DateTimeZone('Europe/Kyiv')));

        $html = $this->render([], [$pass]);

        $this->assertStringContainsString('Бригада, ремонт', $html);
        $this->assertStringContainsString('кв. 85', $html);
        $this->assertStringContainsString('активний', $html);
    }

    public function testAnEmptyLogStillRenders(): void
    {
        $this->assertStringContainsString('Сканування QR', $this->render([]));
    }
}
