<?php

declare(strict_types=1);

namespace TriCoreDb\Internal;

use TriCoreDb\Exception\InvalidValueException;

/**
 * @internal
 */
final class Enums
{
    private function __construct()
    {
    }

    /**
     * Resolve an enum case or its string value to the wire string.
     *
     * @template T of \BackedEnum
     * @param T|string $value
     * @param class-string<T> $enum
     */
    public static function value(\BackedEnum|string $value, string $enum, string $name): string
    {
        if ($value instanceof \BackedEnum) {
            if (!$value instanceof $enum) {
                throw new InvalidValueException(sprintf('%s must be a %s, got %s', $name, $enum, get_debug_type($value)));
            }

            return (string) $value->value;
        }
        $case = $enum::tryFrom($value);
        if ($case === null) {
            $allowed = array_map(static fn (\BackedEnum $c): string => (string) $c->value, $enum::cases());

            throw new InvalidValueException(sprintf('%s must be one of %s, got "%s"', $name, implode(', ', $allowed), $value));
        }

        return (string) $case->value;
    }
}
