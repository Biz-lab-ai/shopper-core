<?php

declare(strict_types=1);

namespace Shopper\Core\Stock;

use Closure;
use LogicException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionObject;
use UnitEnum;

/**
 * A command is repeated after a rollback, so it may carry nothing that the
 * rolled back attempt could have changed in memory: no bound object and only
 * immutable values in its use list (scalars, enums, arrays of them, readonly
 * value objects). Models, collections and closures are refused before the
 * first attempt.
 */
final class RepeatableClosure
{
    public static function assert(Closure $command): void
    {
        $function = new ReflectionFunction($command);

        if ($function->getClosureThis() !== null) {
            throw new LogicException('A repeatable stock command must be a static closure.');
        }

        foreach ($function->getStaticVariables() as $name => $value) {
            if (! self::isImmutable($value)) {
                throw new LogicException("A repeatable stock command cannot capture [\${$name}]: only scalars, enums, arrays of them and readonly value objects are repeatable.");
            }
        }
    }

    private static function isImmutable(mixed $value): bool
    {
        if ($value === null || is_scalar($value) || $value instanceof UnitEnum) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::isImmutable($item)) {
                    return false;
                }
            }

            return true;
        }

        if (! is_object($value) || $value instanceof Closure || ! (new ReflectionClass($value))->isReadOnly()) {
            return false;
        }

        foreach ((new ReflectionObject($value))->getProperties() as $property) {
            if ($property->isInitialized($value) && ! self::isImmutable($property->getValue($value))) {
                return false;
            }
        }

        return true;
    }
}
