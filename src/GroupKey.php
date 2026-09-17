<?php

declare(strict_types=1);

namespace TriCoreDb;

/**
 * The key a `$group` stage groups by.
 */
final class GroupKey implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $wire
     */
    private function __construct(private readonly array $wire)
    {
    }

    /** Group by the value at a dot-notation path. */
    public static function field(string $path): self
    {
        return new self(['Field' => $path]);
    }

    /** Put every document in one group keyed by a constant. */
    public static function constant(mixed $value): self
    {
        return new self(['Constant' => $value]);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->wire;
    }
}
