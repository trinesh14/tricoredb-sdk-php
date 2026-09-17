<?php

declare(strict_types=1);

namespace TriCoreDb;

/**
 * Builders for the server's `DocumentFilter`.
 *
 * There is no `Or`, `Not` or regex: that is the server's vocabulary.
 */
final class Filter implements \JsonSerializable
{
    private function __construct(private readonly mixed $wire)
    {
    }

    /** Match every document. */
    public static function all(): self
    {
        return new self('All');
    }

    /** `field` equals `value`. */
    public static function eq(string $field, mixed $value): self
    {
        return self::compare('Eq', $field, $value);
    }

    /** `field` does not equal `value`. */
    public static function ne(string $field, mixed $value): self
    {
        return self::compare('Ne', $field, $value);
    }

    /** `field` is greater than `value`. */
    public static function gt(string $field, mixed $value): self
    {
        return self::compare('Gt', $field, $value);
    }

    /** `field` is greater than or equal to `value`. */
    public static function gte(string $field, mixed $value): self
    {
        return self::compare('Gte', $field, $value);
    }

    /** `field` is less than `value`. */
    public static function lt(string $field, mixed $value): self
    {
        return self::compare('Lt', $field, $value);
    }

    /** `field` is less than or equal to `value`. */
    public static function lte(string $field, mixed $value): self
    {
        return self::compare('Lte', $field, $value);
    }

    /**
     * `field` equals any of `values`.
     *
     * @param array<mixed> $values
     */
    public static function in(string $field, array $values): self
    {
        return new self(['In' => ['field' => $field, 'values' => array_values($values)]]);
    }

    /** `field` (an array) contains `value`. */
    public static function contains(string $field, mixed $value): self
    {
        return self::compare('Contains', $field, $value);
    }

    /** Every sub-filter matches. */
    public static function and(self ...$filters): self
    {
        return new self(['And' => array_values($filters)]);
    }

    /** The externally tagged wire form. */
    public function jsonSerialize(): mixed
    {
        return $this->wire;
    }

    private static function compare(string $variant, string $field, mixed $value): self
    {
        return new self([$variant => ['field' => $field, 'value' => $value]]);
    }
}
