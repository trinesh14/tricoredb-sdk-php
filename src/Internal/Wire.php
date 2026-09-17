<?php

declare(strict_types=1);

namespace TriCoreDb\Internal;

use TriCoreDb\Bytes;
use TriCoreDb\ErrorCode;
use TriCoreDb\Exception\InvalidValueException;
use TriCoreDb\Exception\ProtocolException;

/**
 * Conversions between PHP byte strings and the `Vec<u8>` JSON number arrays
 * the protocol uses.
 *
 * @internal
 */
final class Wire
{
    private const PACK_CHUNK = 8192;

    private function __construct()
    {
    }

    /**
     * @return list<int>
     */
    public static function toByteList(string|Bytes $bytes): array
    {
        $raw = $bytes instanceof Bytes ? $bytes->raw() : $bytes;
        if ($raw === '') {
            return [];
        }
        /** @var array<int, int> $values */
        $values = unpack('C*', $raw);

        return array_values($values);
    }

    /**
     * @param mixed $list A JSON array of integers 0-255.
     * @param string $what What the value is, for the error message.
     * @throws ProtocolException When the value is not a byte array.
     */
    public static function fromByteList(mixed $list, string $what): string
    {
        if (!is_array($list)) {
            throw new ProtocolException(sprintf('expected a byte array for %s, got %s', $what, get_debug_type($list)), ErrorCode::PROTOCOL);
        }
        foreach ($list as $byte) {
            if (!is_int($byte) || $byte < 0 || $byte > 255) {
                throw new ProtocolException(sprintf('expected a byte array for %s, found %s', $what, var_export($byte, true)), ErrorCode::PROTOCOL);
            }
        }
        $out = '';
        foreach (array_chunk($list, self::PACK_CHUNK) as $chunk) {
            $out .= pack('C*', ...$chunk);
        }

        return $out;
    }

    /**
     * @param mixed $value A byte string or {@see Bytes}.
     * @param string $name The argument name, for the error message.
     * @return list<int>
     */
    public static function argument(mixed $value, string $name): array
    {
        if (is_string($value) || $value instanceof Bytes) {
            return self::toByteList($value);
        }

        throw new InvalidValueException(sprintf('%s must be a string or Bytes, got %s', $name, get_debug_type($value)));
    }
}
