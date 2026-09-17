<?php

declare(strict_types=1);

namespace TriCoreDb\Internal;

use TriCoreDb\Bytes;
use TriCoreDb\Decimal;
use TriCoreDb\Exception\InvalidValueException;

/**
 * SQL parameter encoding for server-side binding.
 *
 * The server accepts JSON scalars only: null, bool, string, number.
 *
 * @internal
 */
final class Params
{
    /** 2^53: past this a float no longer names one integer. */
    private const EXACT_FLOAT_INTEGER_LIMIT = 9007199254740992.0;

    /** 2^63: past this no INT or BIGINT column could have been the target. */
    private const INTEGER_COLUMN_LIMIT = 9223372036854775808.0;

    private function __construct()
    {
    }

    /**
     * @param list<mixed> $params
     * @return list<bool|float|int|string|null>
     */
    public static function encode(array $params): array
    {
        $out = [];
        foreach (array_values($params) as $i => $value) {
            $out[] = self::encodeOne($value, $i + 1);
        }

        return $out;
    }

    /**
     * @param mixed $value The PHP value.
     * @param int $position 1-based placeholder position, for error messages.
     * @throws InvalidValueException When the value has no exact wire form.
     */
    public static function encodeOne(mixed $value, int $position): bool|float|int|string|null
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidValueException(sprintf('parameter #%d is %s, which has no SQL value', $position, var_export($value, true)));
            }
            $magnitude = abs($value);
            if (floor($value) === $value && $magnitude >= self::EXACT_FLOAT_INTEGER_LIMIT && $magnitude <= self::INTEGER_COLUMN_LIMIT) {
                throw new InvalidValueException(sprintf(
                    'parameter #%d is the float %s, an integer too large for a float to hold exactly, so it has '
                    . 'already lost precision. Pass it as an int, or as a Decimal for an exact value.',
                    $position,
                    sprintf('%.0f', $value)
                ));
            }

            return $value;
        }
        if ($value instanceof Decimal) {
            return (string) $value;
        }
        if ($value instanceof Bytes) {
            return '0x' . $value->toHex();
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        throw new InvalidValueException(sprintf(
            'parameter #%d is %s; SQL parameters are null, bool, int, float, string, Decimal, Bytes or '
            . 'DateTimeInterface. Encode structured data yourself (for example json_encode for a JSON column).',
            $position,
            get_debug_type($value)
        ));
    }
}
