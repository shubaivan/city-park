<?php

namespace App\Tests\Telegram;

use App\Service\ComplaintService;
use App\Telegram\Complaint\Command\ComplaintMenuCommand;
use PHPUnit\Framework\TestCase;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaPhoto;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;

/**
 * The two ways this feature has already broken silently, pinned so they cannot recur.
 *
 * Both failures were invisible in testing: a wrong `InputFile` import makes every photo
 * card fall back to text through a `catch (\Throwable)`, and a callback whose handler
 * method does not exist only fails when somebody actually taps that button on prod.
 * Neither shows up in a container lint or a unit test of the service.
 */
class ComplaintHandlerWiringTest extends TestCase
{
    /**
     * `Types\Input\InputFile` exists in Nutgram and is not the class sendPhoto() wants —
     * importing it costs nothing at boot and turns every photo card into text. There is a
     * comment in RentalMenuCommand about this exact mistake; it happened again anyway.
     */
    public function testPhotoClassesAreTheOnesNutgramAccepts(): void
    {
        $this->assertTrue(class_exists(InputFile::class));
        $this->assertTrue(class_exists(InputMediaPhoto::class));

        $imports = file_get_contents(
            __DIR__ . '/../../src/Telegram/Complaint/Command/ComplaintMenuCommand.php',
        );

        $this->assertStringContainsString(
            'use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;',
            $imports,
            'sendPhoto() needs Types\Internal\InputFile — Types\Input\InputFile also exists and silently degrades the card to text',
        );

        $this->assertStringContainsString(
            'use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;',
            file_get_contents(__DIR__ . '/../../src/Service/ComplaintService.php'),
        );
    }

    /**
     * Every branch of the callback dispatcher must land on a method that exists. One of
     * them did not, because an unrelated edit removed two methods along with the dead one
     * it was aiming at, and the button simply threw on prod.
     */
    public function testEveryDispatchedCallbackHasAHandlerMethod(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../src/Telegram/Complaint/Command/ComplaintMenuCommand.php',
        );

        preg_match_all('/\$this->(\w+)\(\$bot[,)]/', $source, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $method) {
            $this->assertTrue(
                method_exists(ComplaintMenuCommand::class, $method),
                sprintf('ComplaintMenuCommand::%s() is called but does not exist', $method),
            );
        }
    }

    public function testServiceMethodsTheHandlerCallsExist(): void
    {
        foreach (['delete', 'changeStatus', 'issuePhotoToken', 'label', 'statusLabel', 'mayFile', 'isManager'] as $method) {
            $this->assertTrue(
                method_exists(ComplaintService::class, $method),
                sprintf('ComplaintService::%s() is missing', $method),
            );
        }
    }
}
