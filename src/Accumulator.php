<?php

declare(strict_types=1);

namespace TriCoreDb;

/**
 * A `$group` accumulator (`GroupAccumulator`): an operation and the output
 * field it writes into the group document.
 */
final class Accumulator implements \JsonSerializable
{
    private function __construct(private readonly string $output, private readonly mixed $op)
    {
    }

    /** Sum of the numeric values at `field`. */
    public static function sum(string $output, string $field): self
    {
        return new self($output, ['Sum' => $field]);
    }

    /** Mean of the numeric values at `field`. */
    public static function avg(string $output, string $field): self
    {
        return new self($output, ['Avg' => $field]);
    }

    /** Smallest value at `field`. */
    public static function min(string $output, string $field): self
    {
        return new self($output, ['Min' => $field]);
    }

    /** Largest value at `field`. */
    public static function max(string $output, string $field): self
    {
        return new self($output, ['Max' => $field]);
    }

    /** Number of documents in the group. */
    public static function count(string $output): self
    {
        return new self($output, 'Count');
    }

    /**
     * @return array{output: string, op: mixed}
     */
    public function jsonSerialize(): array
    {
        return ['output' => $this->output, 'op' => $this->op];
    }
}
