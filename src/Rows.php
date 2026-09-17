<?php

declare(strict_types=1);

namespace TriCoreDb;

/**
 * A SQL result set.
 *
 * Every cell is the server's own text rendering of the value: a number arrives
 * as its digits, and a SQL `NULL` arrives as the text `NULL`.
 *
 * @implements \IteratorAggregate<int, list<string|null>>
 */
final class Rows implements \Countable, \IteratorAggregate
{
    /**
     * @param list<string> $columns Column names, in order.
     * @param list<list<string|null>> $rows Row values, in column order.
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows
    ) {
    }

    /**
     * Rows as associative arrays keyed by column name.
     *
     * @return list<array<string, string|null>>
     */
    public function toAssoc(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            $assoc = [];
            foreach ($this->columns as $i => $column) {
                $assoc[$column] = $row[$i] ?? null;
            }
            $out[] = $assoc;
        }

        return $out;
    }

    /**
     * One cell by row index and column name; null when either is absent.
     */
    public function value(int $row, string $column): ?string
    {
        $index = array_search($column, $this->columns, true);
        if ($index === false || !isset($this->rows[$row])) {
            return null;
        }

        return $this->rows[$row][$index] ?? null;
    }

    /** Number of rows. */
    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * @return \ArrayIterator<int, list<string|null>>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->rows);
    }
}
