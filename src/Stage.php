<?php

declare(strict_types=1);

namespace TriCoreDb;

use TriCoreDb\Exception\InvalidValueException;

/**
 * Builders for aggregation pipeline stages (`AggregateStage`).
 *
 * Stages apply strictly in the order given.
 */
final class Stage implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $wire
     */
    private function __construct(private readonly array $wire)
    {
    }

    /** Keep documents matching the filter. */
    public static function match(Filter $filter): self
    {
        return new self(['Match' => $filter]);
    }

    /** Group by a key, computing accumulators into each group document. */
    public static function group(GroupKey $by, Accumulator ...$accumulators): self
    {
        return new self(['Group' => ['by' => $by, 'accumulators' => array_values($accumulators)]]);
    }

    /**
     * Sort by one or more keys.
     *
     * @param list<string|array{field: string, descending?: bool}> $keys A field name sorts ascending.
     */
    public static function sort(array $keys): self
    {
        $wire = [];
        foreach ($keys as $key) {
            if (is_string($key)) {
                $wire[] = ['field' => $key, 'descending' => false];
                continue;
            }
            if (!is_array($key) || !is_string($key['field'] ?? null)) {
                throw new InvalidValueException('each sort key is a field name or ["field" => ..., "descending" => bool]');
            }
            $wire[] = ['field' => $key['field'], 'descending' => (bool) ($key['descending'] ?? false)];
        }

        return new self(['Sort' => $wire]);
    }

    /** Skip the first `n` documents. */
    public static function skip(int $n): self
    {
        return new self(['Skip' => self::count0($n, 'skip')]);
    }

    /** Keep at most `n` documents. */
    public static function limit(int $n): self
    {
        return new self(['Limit' => self::count0($n, 'limit')]);
    }

    /**
     * Keep (`include`) or drop the named top-level fields.
     *
     * @param list<string> $fields
     */
    public static function project(array $fields, bool $include = true): self
    {
        return new self(['Project' => ['fields' => array_values($fields), 'include' => $include]]);
    }

    /** Replace the stream with one document holding the input count in `field`. */
    public static function count(string $field): self
    {
        return new self(['Count' => ['field' => $field]]);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->wire;
    }

    private static function count0(int $n, string $name): int
    {
        if ($n < 0) {
            throw new InvalidValueException(sprintf('%s must not be negative', $name));
        }

        return $n;
    }
}
