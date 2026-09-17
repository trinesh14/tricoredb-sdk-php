<?php

declare(strict_types=1);

namespace TriCoreDb;

/**
 * One entry of a cache stream.
 */
final class StreamEntry
{
    /**
     * @param string $id The entry's `<ms>-<seq>` id.
     * @param list<array{0: string, 1: string}> $fields Field/value byte pairs, in the order the server returned them.
     */
    public function __construct(
        public readonly string $id,
        public readonly array $fields
    ) {
    }

    /**
     * The fields as a map. A field that repeats keeps its last value, and PHP
     * turns a numeric field name into an integer key; {@see StreamEntry::$fields}
     * stays authoritative.
     *
     * @return array<array-key, string>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->fields as [$field, $value]) {
            $out[$field] = $value;
        }

        return $out;
    }
}
