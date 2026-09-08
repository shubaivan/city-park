<?php

namespace App\Tests\Service;

use App\Entity\TelegramUser;
use App\Service\AvatarService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The rules around residents' faces, each of which is easy to undo by accident.
 */
class AvatarRulesTest extends KernelTestCase
{
    private function service(): AvatarService
    {
        self::bootKernel();

        return self::getContainer()->get(AvatarService::class);
    }

    private function user(array $fields): TelegramUser
    {
        $user = new TelegramUser();

        foreach ($fields as $setter => $value) {
            $user->{$setter}($value);
        }

        return $user;
    }

    /**
     * **Never under `public/`.**
     *
     * A cached avatar under the document root is a resident's face on a URL anybody who
     * guesses it can open. It is served through `/admin/avatar/{id}`, behind the same
     * login as their phone number and their debt.
     */
    public function testTheCacheLivesOutsideTheDocumentRoot(): void
    {
        $directory = $this->service()->directory();

        $this->assertStringEndsWith('/var/avatars', $directory);
        $this->assertStringNotContainsString('/public/', $directory . '/');
    }

    /**
     * `photo_path` is a bare file name and nothing else.
     *
     * It is written only by the sync, but it is read to build a filesystem path, and a
     * column that has ever held «../» is a column that can serve any file on the box.
     */
    public function testAPathThatIsNotABareFileNameIsRefused(): void
    {
        $service = $this->service();

        foreach (['../../.env', 'a/b.jpg', '/etc/passwd'] as $bad) {
            $this->assertNull(
                $service->fileFor($this->user(['setPhotoPath' => $bad])),
                $bad . ': anything but a bare file name must be refused',
            );
        }
    }

    /** No photo is the common case, so the circle must never come out empty. */
    public function testInitialsAlwaysSaySomething(): void
    {
        $this->assertSame('КМ', $this->user(['setFullName' => 'Конакбаєва Марина Василівна'])->getInitials());
        $this->assertSame('IS', $this->user(['setFirstName' => 'Ivan', 'setLastName' => 'Shuba'])->getInitials());
        $this->assertSame('B', $this->user(['setUsername' => 'bogbog'])->getInitials());
        $this->assertSame('?', (new TelegramUser())->getInitials());
    }

    /**
     * The registry name wins over the Telegram one.
     *
     * «Конакбаєва Марина» is who the accountant is looking for; «Ника» is not, and the two
     * letters are the whole of what the circle can say.
     */
    public function testTheRegistryNameIsPreferred(): void
    {
        $user = $this->user([
            'setFullName' => 'Конакбаєва Марина Василівна',
            'setFirstName' => 'Ника',
        ]);

        $this->assertSame('КМ', $user->getInitials());
    }

    /** Both panel roles may see a face; the gate lives with every other one. */
    public function testTheRouteIsGatedInAccessControl(): void
    {
        $security = (string)file_get_contents(__DIR__ . '/../../config/packages/security.yaml');

        $this->assertMatchesRegularExpression(
            '#\^/admin/avatar.*methods: \[GET\].*ROLE_COMPLAINTS#',
            $security,
            'the avatar route must be readable by both roles and by GET only',
        );
    }

    /**
     * The list carries the avatar as a plain row key, never as two more columns.
     *
     * Every `columnDef` in `telegram_users.js` targets its column by **index**, so a column
     * inserted anywhere but the end repaints the wrong cells — the debt renderer onto the
     * area, the area onto the threshold. DataTables hands the whole JSON row to a renderer,
     * so the picture needs no column at all; `block_reason_label` has travelled that way
     * for the same reason.
     */
    public function testTheAvatarTravelsWithTheRowNotAsAColumn(): void
    {
        $controller = (string)file_get_contents(__DIR__ . '/../../src/Controller/AdminController.php');
        $js = (string)file_get_contents(__DIR__ . '/../../assets/js/telegram_users.js');

        $this->assertStringContainsString("\$row['avatar']", $controller);
        $this->assertStringContainsString('row.avatar', $js, 'the name renderer draws it');

        $this->assertStringNotContainsString(
            "\$fieldNames[] = 'avatar'",
            $controller,
            'a new column would shift every indexed columnDef in telegram_users.js',
        );
    }

    /**
     * Tapping a face opens the full-size copy, and it must never open nothing.
     *
     * The big file only exists for people synced since it was added, so `/full` falls back
     * to the small one rather than answering 404 — an enlarge that fails is worse than one
     * that shows a 160px picture.
     */
    public function testTheEnlargeFallsBackToTheSmallCopy(): void
    {
        $service = $this->service();
        $user = $this->user(['setPhotoPath' => 'nope.jpg']);

        // Neither file is on disk here; what is being pinned is which column is read.
        $source = (string)file_get_contents(__DIR__ . '/../../src/Service/AvatarService.php');

        $this->assertStringContainsString(
            '$user->getPhotoFullPath() ?? $user->getPhotoPath()',
            $source,
            'the full size must fall back to the small one',
        );
        $this->assertNull($service->fileFor($user, full: true));
    }

    /**
     * The trigger is a real link, and that is load-bearing twice over.
     *
     * Without JS it still opens the picture; and in the users table the row's own handler
     * opens the resident's card on a click anywhere except inside `a, button, input, …`,
     * so an `<a>` is what stops one tap doing both things at once.
     */
    public function testTheEnlargeTriggerIsALink(): void
    {
        $js = (string)file_get_contents(__DIR__ . '/../../assets/js/telegram_users.js');
        $card = (string)file_get_contents(__DIR__ . '/../../templates/admin/resident.html.twig');

        $this->assertStringContainsString('<a href="\' + row.avatar + \'/full" data-avatar-full>', $js);
        $this->assertStringContainsString('data-avatar-full', $card);

        $this->assertStringContainsString(
            "closest('a, button, input, select, label, .dtr-control, .dtr-details')",
            $js,
            'the row handler must keep ignoring clicks inside a link',
        );
    }

    /**
     * The sync deletes what Telegram no longer shows.
     *
     * Somebody who closes their profile has taken the photo back; a copy that outlives the
     * withdrawal is the panel keeping something the person withdrew.
     */
    public function testAVanishedPhotoIsForgottenNotKept(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../src/Service/AvatarService.php');

        $this->assertMatchesRegularExpression(
            '/if \(!\$sizes\) \{\s*return \$this->forget\(/',
            $source,
            'no photo from Telegram must clear the cached one',
        );
        $this->assertStringContainsString('@unlink($file)', $source, 'and delete the file, not only the column');
    }
}
