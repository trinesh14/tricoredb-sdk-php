<?php

declare(strict_types=1);

namespace TriCoreDb;

/**
 * One read-only source for an LLM context bundle.
 */
final class LlmSource implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $wire
     */
    private function __construct(private readonly array $wire)
    {
    }

    /** The rows of a SQL query. */
    public static function sql(string $query): self
    {
        return new self(['Sql' => ['query' => $query]]);
    }

    /** Documents from a collection, optionally filtered and limited. */
    public static function documentFind(string $collection, ?Filter $filter = null, ?int $limit = null): self
    {
        return new self(['DocumentFind' => [
            'collection' => $collection,
            'filter' => $filter ?? Filter::all(),
            'limit' => $limit,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->wire;
    }
}
