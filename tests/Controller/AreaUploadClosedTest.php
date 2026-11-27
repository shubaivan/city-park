<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

/**
 * The area registry is not uploaded through the web by anybody (27.11.2026).
 *
 * That morning it went into the debt form and zeroed 438 debts. The owner's call: the
 * house does not change, the registry was loaded once, and from now on only he updates it,
 * from the console (`area:import-registry`). So the upload is refused to every login the
 * panel has — the accountant's and the main admin's included — and the page offers no form.
 */
class AreaUploadClosedTest extends WebTestCase
{
    private function loginAs(string $login): KernelBrowser
    {
        $client = static::createClient();
        $provider = static::getContainer()->get('security.user.provider.concrete.users_in_memory');
        $client->loginUser($provider->loadUserByIdentifier($login));

        return $client;
    }

    /** @return array<string, array{string}> */
    public static function everyLogin(): array
    {
        return [
            'the main admin' => ['main_admin'],
            'the accountant' => ['alina'],
            'the head of the ОСББ' => ['luda_boss'],
            'the complaints role' => ['serhii'],
            'the guard' => ['test_guard'],
        ];
    }

    /** @dataProvider everyLogin */
    public function testTheUploadIsRefusedToEveryLogin(string $login): void
    {
        $client = $this->loginAs($login);
        $tmp = tempnam(sys_get_temp_dir(), 'area_');
        file_put_contents($tmp, 'not really a spreadsheet');

        try {
            $client->request('POST', '/admin/area/upload', files: [
                'area_file' => new UploadedFile($tmp, 'Реєстр-особових-рахунків (43).xlsx', null, null, true),
            ]);
        } finally {
            @unlink($tmp);
        }

        // 403 exactly: a 302 is what an unauthenticated client gets, and would prove nothing.
        $this->assertSame(403, $client->getResponse()->getStatusCode(), $login . ' must not upload the area registry');
    }

    public function testThePageShowsTheNoteAndNoForm(): void
    {
        self::bootKernel();
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(new InMemoryUser('alina', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']),
        );

        $html = self::getContainer()->get(Environment::class)->render('admin/area.html.twig', [
            'total' => 966,
            'with_area' => 960,
            'archive' => [[
                'name' => '2026-09-04_10-00-00-alina.xlsx',
                'size' => 40960,
                'uploaded_at' => new \DateTimeImmutable('2026-09-04 10:00'),
                'actor' => 'alina',
            ]],
        ]);

        $this->assertStringContainsString('Реєстр площ зафіксовано', $html);
        $this->assertStringContainsString('Івану', $html);
        $this->assertStringNotContainsString('/admin/area/upload', $html);
        $this->assertStringNotContainsString('type="file"', $html);
        // Still readable: the counts and the archive.
        $this->assertStringContainsString('960', $html);
        $this->assertStringContainsString('/admin/import-archive/area/2026-09-04_10-00-00-alina.xlsx', $html);
    }
}
