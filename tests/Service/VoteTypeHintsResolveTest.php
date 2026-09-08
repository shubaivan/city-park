<?php

namespace App\Tests\Service;

use App\Service\BlockVoteService;
use PHPUnit\Framework\TestCase;

/**
 * Every type hint in the vote service must resolve to a class that exists.
 *
 * `BlockVoteService` lives in `App\Service`, so an unimported `?TelegramUser` resolves to
 * `App\Service\TelegramUser` — which does not exist. PHP does not complain at parse time,
 * `php -l` passes, the container lints, and the whole test suite goes green, because the
 * type is only checked when the method is actually called with an argument.
 *
 * It shipped on 08.09.2026 and broke every ballot for eleven minutes: recordVote() threw a
 * TypeError, /hook answered 500, and Telegram retried the same tap for as long as the
 * resident kept pressing. From the outside — «нажимаю на За і нічого не відбувається,
 * підсвічує кнопку і все», which is what a resident reported.
 *
 * Reflection resolves the hints the way PHP will at call time, so this catches it before
 * anybody presses anything.
 */
class VoteTypeHintsResolveTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function methods(): iterable
    {
        foreach (get_class_methods(BlockVoteService::class) as $method) {
            yield $method => [$method];
        }
    }

    /** @dataProvider methods */
    public function testEveryParameterTypeExists(string $method): void
    {
        $reflection = new \ReflectionMethod(BlockVoteService::class, $method);

        // A method with no parameters has nothing to check and must still count as
        // examined, or PHPUnit fails it for performing no assertions.
        $this->assertNotNull($reflection->getName());

        foreach ($reflection->getParameters() as $parameter) {
            foreach (self::classNames($parameter->getType()) as $class) {
                $this->assertTrue(
                    class_exists($class) || interface_exists($class),
                    sprintf(
                        '%s(): $%s is hinted %s, which does not exist — an unimported class '
                            . 'resolves into this file\'s own namespace and only fails when '
                            . 'the method is called.',
                        $method,
                        $parameter->getName(),
                        $class,
                    ),
                );
            }
        }
    }

    /** @return string[] */
    private static function classNames(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }

        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            return array_merge(...array_map(
                static fn (\ReflectionType $t): array => self::classNames($t),
                $type->getTypes(),
            ));
        }

        return [];
    }
}
