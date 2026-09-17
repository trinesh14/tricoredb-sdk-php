<?php

declare(strict_types=1);

namespace TriCoreDb;

/**
 * Stable machine-readable error codes. Branch on these, never on messages.
 *
 * Request-level codes arrive in a RESPONSE's `diagnostics.error_code`;
 * frame-level codes arrive in an ERROR frame or a refused HELLO_OK.
 */
final class ErrorCode
{
    public const NOT_LEADER = 'not_leader';
    public const PERM_DENIED = 'perm.denied';
    public const REQUEST_INVALID = 'request.invalid';
    public const REQUEST_MALFORMED = 'request.malformed';
    public const ENGINE_DISABLED = 'engine.disabled';
    public const LIMIT_EXCEEDED = 'limit.exceeded';
    public const STATE_CONFLICT = 'state.conflict';
    public const INTERNAL = 'internal';

    public const PROTOCOL = 'protocol';
    public const ORDER = 'order';
    public const REQUEST = 'request';
    public const FRAME_VERSION = 'frame_version';
    public const FRAME_TAG = 'frame_tag';
    public const HANDSHAKE_MALFORMED = 'handshake_malformed';
    public const FRAME_TOO_LARGE = 'frame_too_large';
    public const CONNECTION_LIMIT = 'connection_limit';
    public const AUTH_REVOKED = 'auth_revoked';
    public const IDLE_IN_TRANSACTION_TIMEOUT = 'idle_in_transaction_timeout';
    public const PROTOCOL_NAME = 'protocol_name';
    public const PROTOCOL_VERSION = 'protocol_version';
    public const FEATURE_NOT_GRANTED = 'feature_not_granted';
    public const TIMEOUT = 'timeout';
    public const CANCELLED = 'cancelled';
    public const NOT_IMPLEMENTED = 'not_implemented';

    private function __construct()
    {
    }
}
