<?php

declare(strict_types=1);

namespace TriCoreDb\Exception;

use TriCoreDb\ErrorCode;

/**
 * Base class of every exception this SDK throws.
 *
 * `getErrorCode()` is the server's stable reason (see {@see ErrorCode}), or
 * null when there is none. PHP's own `getCode()` is unused and always 0.
 */
class TriCoreException extends \RuntimeException
{
    private ?string $errorCode;

    private ?string $leaderHint;

    /**
     * @param string $message Human-readable description.
     * @param string|null $errorCode Machine-readable code, if the server sent one.
     * @param string|null $leaderHint `host:port` of the leader, only with `not_leader`.
     * @param \Throwable|null $previous The underlying cause.
     */
    public function __construct(
        string $message,
        ?string $errorCode = null,
        ?string $leaderHint = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->leaderHint = $leaderHint;
    }

    /**
     * The machine-readable error code, or null.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The `host:port` a `not_leader` refusal names, or null.
     *
     * Absent mid-election, on a node without Raft, or when the leader has no
     * configured address. This SDK never dials it for you.
     */
    public function getLeaderHint(): ?string
    {
        return $this->leaderHint;
    }

    /**
     * Whether the request was right but reached a node that is not the leader.
     *
     * True for every `not_leader` refusal, with or without a leader hint.
     */
    public function isRedirect(): bool
    {
        return $this->errorCode === ErrorCode::NOT_LEADER;
    }
}
