<?php

declare(strict_types=1);

namespace TriCoreDb;

use TriCoreDb\Exception\ProtocolException;
use TriCoreDb\Internal\Wire;

/**
 * A server response: the externally tagged `ResponseData` plus diagnostics.
 */
final class Response
{
    /**
     * @param string $requestId The request id this answers.
     * @param string $status `ok`, `error` or `not_implemented`.
     * @param mixed $data The decoded `ResponseData`, e.g. `['Json' => ...]` or `"Empty"`.
     * @param array<string, mixed> $diagnostics The decoded `ResponseDiagnostics`.
     */
    public function __construct(
        public readonly string $requestId,
        public readonly string $status,
        public readonly mixed $data,
        public readonly array $diagnostics
    ) {
    }

    /**
     * Build a response from a decoded RESPONSE frame body.
     *
     * @throws ProtocolException When the body is not a response.
     */
    public static function fromWire(mixed $body): self
    {
        if (!is_array($body) || !is_string($body['status'] ?? null) || !array_key_exists('data', $body)) {
            throw new ProtocolException('a RESPONSE frame did not carry a status and data', ErrorCode::PROTOCOL);
        }
        $diagnostics = $body['diagnostics'] ?? [];

        return new self(
            is_string($body['request_id'] ?? null) ? $body['request_id'] : '',
            $body['status'],
            $body['data'],
            is_array($diagnostics) ? $diagnostics : []
        );
    }

    /** Whether the status is `ok`. */
    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    /** `diagnostics.error_code`, or null. */
    public function getErrorCode(): ?string
    {
        $code = $this->diagnostics['error_code'] ?? null;

        return is_string($code) ? $code : null;
    }

    /** `diagnostics.leader_hint` (a `host:port`), or null. */
    public function getLeaderHint(): ?string
    {
        $hint = $this->diagnostics['leader_hint'] ?? null;

        return is_string($hint) && $hint !== '' ? $hint : null;
    }

    /** Whether this response is a `not_leader` refusal. */
    public function isRedirect(): bool
    {
        return $this->getErrorCode() === ErrorCode::NOT_LEADER;
    }

    /**
     * Non-fatal warnings. A broadcast that missed a shard reports it here while still `ok`.
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        $warnings = $this->diagnostics['warnings'] ?? [];

        return is_array($warnings) ? array_values(array_filter($warnings, 'is_string')) : [];
    }

    /** The `ResponseData` variant name: `Json`, `Rows`, `Documents`, `CacheValue`, `Toon`, `Message` or `Empty`. */
    public function dataKind(): string
    {
        if (is_string($this->data)) {
            return $this->data;
        }
        if (is_array($this->data) && $this->data !== []) {
            return (string) array_key_first($this->data);
        }

        return get_debug_type($this->data);
    }

    /** Whether the data is the named variant. */
    public function hasData(string $variant): bool
    {
        return is_array($this->data) && array_key_exists($variant, $this->data);
    }

    /**
     * The `Json` payload. Null is a legitimate value (a lookup miss).
     *
     * @throws ProtocolException When the data is another variant.
     */
    public function json(string $what = 'response'): mixed
    {
        $this->expect('Json', $what);

        return $this->data['Json'];
    }

    /**
     * The `Documents` payload.
     *
     * @return list<array<string, mixed>>
     * @throws ProtocolException When the data is another variant.
     */
    public function documents(string $what = 'response'): array
    {
        $this->expect('Documents', $what);
        $documents = $this->data['Documents'];
        if (!is_array($documents)) {
            throw new ProtocolException(sprintf('Documents for %s is not a list', $what), ErrorCode::PROTOCOL);
        }

        return array_values($documents);
    }

    /**
     * The `Rows` payload.
     *
     * @throws ProtocolException When the data is another variant.
     */
    public function rows(string $what = 'response'): Rows
    {
        $this->expect('Rows', $what);
        $rows = $this->data['Rows'];
        if (!is_array($rows) || !is_array($rows['columns'] ?? null) || !is_array($rows['rows'] ?? null)) {
            throw new ProtocolException(sprintf('Rows for %s lacks columns or rows', $what), ErrorCode::PROTOCOL);
        }

        return new Rows(array_values($rows['columns']), array_values($rows['rows']));
    }

    /**
     * The `CacheValue` payload as raw bytes, or null on a miss.
     *
     * @throws ProtocolException When the data is another variant.
     */
    public function cacheValue(string $what = 'response'): ?string
    {
        $this->expect('CacheValue', $what);
        $value = $this->data['CacheValue'];

        return $value === null ? null : Wire::fromByteList($value, $what);
    }

    /** The `Message` payload, or null when the data is another variant. */
    public function message(): ?string
    {
        return $this->hasData('Message') && is_string($this->data['Message']) ? $this->data['Message'] : null;
    }

    /**
     * Whichever rendering an export produced: `Toon` text, `Json` value or `Message` text.
     *
     * @throws ProtocolException When the data is none of those.
     */
    public function rendered(string $what = 'response'): mixed
    {
        foreach (['Toon', 'Json', 'Message'] as $variant) {
            if ($this->hasData($variant)) {
                return $this->data[$variant];
            }
        }

        throw new ProtocolException(sprintf('expected a rendered export for %s, got %s', $what, $this->dataKind()), ErrorCode::PROTOCOL);
    }

    /** `rows_affected` from a SQL write's `Json` payload, or null when absent. */
    public function rowsAffected(): ?int
    {
        if (!$this->hasData('Json') || !is_array($this->data['Json'])) {
            return null;
        }
        $n = $this->data['Json']['rows_affected'] ?? null;

        return is_int($n) ? $n : null;
    }

    private function expect(string $variant, string $what): void
    {
        if (!$this->hasData($variant)) {
            throw new ProtocolException(sprintf('expected %s for %s, got %s', $variant, $what, $this->dataKind()), ErrorCode::PROTOCOL);
        }
    }
}
