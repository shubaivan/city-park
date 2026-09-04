<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\Complaint;
use App\Entity\ComplaintComment;
use App\Entity\TelegramUser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

/**
 * The admin complaints page, rendered.
 *
 * `lint:twig` proves the file parses; it says nothing about a property that does not
 * exist on the entity, and the page is now built out of three of them (the thread, the
 * author's contact, the hold note). Rendering it here is the only place that fails before
 * Людмила opens it on her phone.
 */
class AdminComplaintsPageTest extends KernelTestCase
{
    /**
     * @param Complaint[] $complaints
     * @param array<int, ComplaintComment[]> $threads
     */
    private function render(array $complaints, array $threads): string
    {
        self::bootKernel();

        // base.html.twig greets app.user unguarded, and every admin page extends it: with
        // no token in the storage the layout itself throws before reaching our block.
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(new InMemoryUser('luda_boss', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']),
        );

        return self::getContainer()->get(Environment::class)->render(
            'admin/complaints.html.twig',
            ['complaints' => $complaints, 'threads' => $threads],
        );
    }

    private function complaint(int $id, string $status = Complaint::STATUS_NEW): Complaint
    {
        $account = (new Account())
            ->setAccountNumber('4100085')
            ->setApartmentNumber('85')
            ->setHouseNumber('19')
            ->setStreet('Козацька');

        $author = new TelegramUser();
        $author->setFirstName('Іван');
        $author->setLastName('Шуба');
        $author->setUsername(null);
        $author->setPhoneNumber('+380 67 123 45 67');

        $complaint = (new Complaint())
            ->setAccount($account)
            ->setAuthor($author)
            ->setText('Не працюють ворота в паркінг біля 12 секції');

        (new \ReflectionProperty(Complaint::class, 'id'))->setValue($complaint, $id);

        if ($status !== Complaint::STATUS_NEW) {
            $complaint->setStatus($status, 'luda_boss');
        }

        return $complaint;
    }

    public function testItRendersAComplaintWithItsThread(): void
    {
        $complaint = $this->complaint(12, Complaint::STATUS_ON_HOLD);
        $complaint->setResolution('чекаємо плату керування, буде за два тижні');

        $comment = (new ComplaintComment())
            ->setComplaint($complaint)
            ->setOfficial(true)
            ->setAuthorLabel('Людмила (голова ОСББ)')
            ->setText('Яка саме секція — ближче до 21 будинку?');

        $html = $this->render([$complaint], [12 => [$comment]]);

        $this->assertStringContainsString('№12', $html);
        $this->assertStringContainsString('⏸ Відкладено', $html);
        $this->assertStringContainsString('чекаємо плату керування', $html);
        $this->assertStringContainsString('Людмила (голова ОСББ)', $html);
        $this->assertStringContainsString('Яка саме секція', $html);
    }

    /**
     * The reason the page was rewritten: she had the flat and no way to reach the person.
     * A tel: link is a tap on a phone, which is where this page is read.
     */
    public function testTheAuthorIsReachableInOneTap(): void
    {
        $html = $this->render([$this->complaint(13)], []);

        $this->assertStringContainsString('Іван Шуба', $html);
        $this->assertStringContainsString('href="tel:+380671234567"', $html);
        $this->assertStringContainsString('https://t.me/+380671234567', $html);
    }

    /** A complaint whose author's row is gone must still render — the FK is SET NULL. */
    public function testItSurvivesAMissingAuthorAndAnEmptyThread(): void
    {
        $complaint = $this->complaint(14);
        $complaint->setAuthor(null);

        $html = $this->render([$complaint], []);

        $this->assertStringContainsString('Автора вже немає в базі', $html);
        $this->assertStringContainsString('Тут ще нічого не написано', $html);
    }
}
