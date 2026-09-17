<?php

declare(strict_types=1);

namespace TriCoreDb;

use TriCoreDb\Exception\InvalidValueException;

/**
 * An exact decimal SQL parameter.
 *
 * A PHP float is binary floating point, so `0.1` is already inexact before it
 * reaches the driver. A `Decimal` is built from text or an integer, keeps its
 * digits and scale (`"1.50"` stays `1.50`), and is sent as plain decimal text
 * with no exponent, which the server parses exactly into a DECIMAL column.
 */
final class Decimal implements \Stringable
{
    private const MAX_EXPONENT = 4096;

    private string $text;

    /**
     * @param string|int $value Decimal text such as `"-12.340"`, `".5"` or `"1.5e3"`, or an integer.
     * @throws InvalidValueException When the text is not a decimal number.
     */
    public function __construct(string|int $value)
    {
        $this->text = is_int($value) ? (string) $value : self::normalise($value);
    }

    /**
     * @param string|int $value See the constructor.
     */
    public static function of(string|int $value): self
    {
        return new self($value);
    }

    /** Plain decimal text, never in exponent form. */
    public function __toString(): string
    {
        return $this->text;
    }

    private static function normalise(string $input): string
    {
        if (!preg_match('/^\s*([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?\s*$/D', $input, $m)) {
            throw new InvalidValueException(sprintf('"%s" is not a decimal number', $input));
        }
        $sign = $m[1] === '-' ? '-' : '';
        $integer = $m[2];
        $fraction = $m[3] ?? '';
        if ($integer === '' && $fraction === '') {
            throw new InvalidValueException(sprintf('"%s" is not a decimal number', $input));
        }
        $exponentText = $m[4] ?? '';
        if ($exponentText !== '' && strlen(ltrim($exponentText, '+-0')) > 5) {
            throw new InvalidValueException(sprintf('"%s" has an exponent too large to write out', $input));
        }
        $exponent = $exponentText === '' ? 0 : (int) $exponentText;
        if (abs($exponent) > self::MAX_EXPONENT) {
            throw new InvalidValueException(sprintf('"%s" has an exponent too large to write out', $input));
        }

        $digits = $integer . $fraction;
        $point = strlen($integer) + $exponent;
        if ($point <= 0) {
            $whole = '0';
            $decimals = str_repeat('0', -$point) . $digits;
        } elseif ($point >= strlen($digits)) {
            $whole = $digits . str_repeat('0', $point - strlen($digits));
            $decimals = '';
        } else {
            $whole = substr($digits, 0, $point);
            $decimals = substr($digits, $point);
        }
        $whole = ltrim($whole, '0');
        if ($whole === '') {
            $whole = '0';
        }
        $text = $decimals === '' ? $whole : $whole . '.' . $decimals;
        if (trim($text, '0.') === '') {
            $sign = '';
        }

        return $sign . $text;
    }
}
