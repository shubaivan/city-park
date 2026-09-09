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
            'now' => new \DateTime('now', new \DateTimeZone('Europe/Kyiv')),
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
        $pass->setActiveUntil(new \DateTime('+2 hours', new \DateTimeZone('Europe/Kyiv')));

        $html = $this->render([], [$pass]);

        $this->assertStringContainsString('Бригада, ремонт', $html);
        $this->assertStringContainsString('кв. 85', $html);
        $this->assertStringContainsString('активний до', $html);
    }

    /**
     * «Що це було» leads to the thing it names.
     *
     * The cell said «👷 пропуск #2» and did nothing, on a page whose whole job is being
     * opened after the fact: the pass is three lines up in a list of near-identical rows,
     * and finding it by eye is what the anchor removes. The flash is the `:target` rule in
     * base.html.twig, shared with /admin/links.
     */
    public function testThePassLinkFindsItsRowUpThePage(): void
    {
        $pass = (new GuestPass())->setAccount($this->flat(9))->setLabel('Бригада, ремонт');
        (new \ReflectionProperty(GuestPass::class, 'id'))->setValue($pass, 4);

        $html = $this->render([$this->scan(QrScan::KIND_GUEST, QrScan::RESULT_OK)], [$pass]);

        $this->assertStringContainsString('href="#pass-4"', $html);
        $this->assertStringContainsString('id="pass-4"', $html, 'the anchor must land on a row that is on the page');
    }

    /**
     * A pass that is no longer listed is not linked to.
     *
     * Revoked passes are left out of the table above, so the anchor would scroll nowhere —
     * and a dead link in a security log reads as the record having been lost, which is the
     * one thing this page must never suggest.
     */
    public function testAScanOfAPassThatIsGoneIsNotALink(): void
    {
        $html = $this->render([$this->scan(QrScan::KIND_GUEST, QrScan::RESULT_REVOKED)]);

        $this->assertStringContainsString('пропуск', $html);
        $this->assertStringNotContainsString('href="#pass-', $html);
    }

    /** A resident scan opens the flat whose code it was. */
    public function testAResidentScanOpensTheObjectCard(): void
    {
        $scan = $this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_NO_BOOKING)
            ->setSubjectAccount($this->flat(12));

        $this->assertStringContainsString('/admin/objects/12', $this->render([$scan]));
    }

    /** …and says the same words with no link when the account is gone (SET NULL). */
    public function testAResidentScanWithoutAnAccountIsStillNamed(): void
    {
        $html = $this->render([$this->scan(QrScan::KIND_RESIDENT, QrScan::RESULT_NO_BOOKING)]);

        $this->assertStringContainsString('мешканець', $html);
        $this->assertStringNotContainsString('/admin/objects/', $html);
    }

    private function flat(int $id): Account
    {
        $account = (new Account())
            ->setAccountNumber('2200' . $id)
            ->setApartmentNumber((string)$id)
            ->setHouseNumber('19')
            ->setStreet('Козацька');

        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }

    public function testAnEmptyLogStillRenders(): void
    {
        $this->assertStringContainsString('Сканування QR', $this->render([]));
    }
}
