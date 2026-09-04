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

    /**
     * Every `cmp:` button the code emits must match a pattern registered in
     * config/telegram.php.
     *
     * The register's callbacks are routed by three regexes, and the list one is a single
     * alternation that has already been extended four times. A button whose data no
     * pattern matches does not error anywhere — it simply spins on the resident's phone
     * and stops, which is indistinguishable from the bot being down.
     */
    public function testEveryCallbackButtonIsRoutedByThePatternsInTheConfig(): void
    {
        $config = file_get_contents(__DIR__ . '/../../config/telegram.php');

        preg_match_all(
            "/onCallbackQueryData\('(\^cmp:[^']+)'/",
            $config,
            $registered,
        );

        $this->assertNotEmpty($registered[1], 'no cmp: routes found in config/telegram.php');

        $sources = [
            'ComplaintMenuCommand' => __DIR__ . '/../../src/Telegram/Complaint/Command/ComplaintMenuCommand.php',
            'ComplaintService' => __DIR__ . '/../../src/Service/ComplaintService.php',
            'ComplaintReply' => __DIR__ . '/../../src/Telegram/Complaint/Command/ComplaintReply.php',
            'ComplaintHold' => __DIR__ . '/../../src/Telegram/Complaint/Command/ComplaintHold.php',
        ];

        foreach ($sources as $name => $file) {
            preg_match_all("/'(cmp:[a-z]+)(:'|')/", file_get_contents($file), $emitted);

            foreach (array_unique($emitted[1]) as $prefix) {
                // A prefix is concatenated with an id at the call site; try both shapes.
                $candidates = [$prefix, $prefix . ':7', $prefix . ':7:done', $prefix . ':7:2'];

                $routed = false;

                foreach ($candidates as $candidate) {
                    foreach ($registered[1] as $pattern) {
                        if (preg_match('/' . $pattern . '/', $candidate) === 1) {
                            $routed = true;

                            break 2;
                        }
                    }
                }

                $this->assertTrue(
                    $routed,
                    sprintf('%s emits "%s", which no pattern in config/telegram.php routes', $name, $prefix),
                );
            }
        }
    }

    public function testServiceMethodsTheHandlerCallsExist(): void
    {
        $methods = [
            'delete', 'changeStatus', 'issuePhotoToken', 'label', 'statusLabel', 'mayFile', 'isManager',
            // The discussion and the manager-only contact block: the card calls all four,
            // and a rename here is a button that throws on prod and nowhere else.
            'mayComment', 'comment', 'thread', 'countComments', 'authorChatUrl', 'authorContactLine',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(ComplaintService::class, $method),
                sprintf('ComplaintService::%s() is missing', $method),
            );
        }
    }
}
