<?php

declare(strict_types=1);

namespace TriCoreDb;

use TriCoreDb\Exception\InvalidValueException;

/**
 * Marks a PHP string as binary data.
 *
 * PHP strings do not distinguish text from bytes, so a plain string SQL
 * parameter binds as TEXT. Wrap it in `Bytes` to bind a BLOB: it is sent as
 * `0x` followed by lowercase hex. Cache APIs treat every string as bytes
 * already and accept either form.
 */
final class Bytes implements \Stringable
{
    private string $raw;

    /**
     * @param string $raw The raw bytes.
     */
    public function __construct(string $raw)
    {
        $this->raw = $raw;
    }

    /**
     * @param string $hex Hex digits, optionally prefixed with `0x`.
     * @throws InvalidValueException When the text is not valid hex.
     */
    public static function fromHex(string $hex): self
    {
        $digits = str_starts_with($hex, '0x') || str_starts_with($hex, '0X') ? substr($hex, 2) : $hex;
        if (strlen($digits) % 2 !== 0 || ($digits !== '' && !ctype_xdigit($digits))) {
            throw new InvalidValueException('not a valid hex byte string');
        }

        return new self((string) hex2bin($digits));
    }

    /** The raw bytes. */
    public function raw(): string
    {
        return $this->raw;
    }

    /** Lowercase hex, without a prefix. */
    public function toHex(): string
    {
        return bin2hex($this->raw);
    }

    /** Number of bytes. */
    public function length(): int
    {
        return strlen($this->raw);
    }

    /** The raw bytes. */
    public function __toString(): string
    {
        return $this->raw;
    }
}
