<?php

declare(strict_types=1);

namespace TriCoreDb\Internal;

use TriCoreDb\ErrorCode;
use TriCoreDb\Exception\InvalidValueException;
use TriCoreDb\Exception\ProtocolException;

/**
 * JSON encoding rules for the wire.
 *
 * PHP has one array type, so `[]` is ambiguous between a JSON list and a JSON
 * object. Every position the Rust types declare as an object goes through
 * {@see Json::object()}, which encodes an empty array as `{}`.
 *
 * @internal
 */
final class Json
{
    public const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR;

    private function __construct()
    {
    }

    /**
     * @throws InvalidValueException When the value is not encodable (invalid UTF-8, NaN, a resource).
     */
    public static function encode(mixed $value): string
    {
        try {
            return json_encode($value, self::ENCODE_FLAGS);
        } catch (\JsonException $e) {
            throw new InvalidValueException('the request cannot be encoded as JSON: ' . $e->getMessage(), null, null, $e);
        }
    }

    /**
     * Decode a frame body. Objects become associative arrays; integers beyond
     * PHP_INT_MAX become strings rather than lossy floats.
     *
     * @throws ProtocolException When the body is not JSON.
     */
    public static function decode(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProtocolException('the server sent a frame body that is not JSON: ' . $e->getMessage(), ErrorCode::PROTOCOL, null, $e);
        }
    }

    /**
     * Normalise a value for a JSON-object position.
     *
     * @param mixed $value An associative array, stdClass or JsonSerializable.
     * @param string $name What the value is, for the error message.
     * @return array<string, mixed>|object
     * @throws InvalidValueException When the value is a non-empty list or a scalar.
     */
    public static function object(mixed $value, string $name): array|object
    {
        if ($value instanceof \stdClass || $value instanceof \JsonSerializable) {
            return $value;
        }
        if (is_array($value)) {
            if ($value === []) {
                return new \stdClass();
            }
            if (array_is_list($value)) {
                throw new InvalidValueException(sprintf(
                    '%s must be a JSON object (an associative array or stdClass), not a list',
                    $name
                ));
            }

            return $value;
        }

        throw new InvalidValueException(sprintf('%s must be a JSON object, got %s', $name, get_debug_type($value)));
    }

    /**
     * Like {@see Json::object()}, but null passes through as JSON null.
     *
     * @return array<string, mixed>|object|null
     */
    public static function objectOrNull(mixed $value, string $name): array|object|null
    {
        return $value === null ? null : self::object($value, $name);
    }
}
