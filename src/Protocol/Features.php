<?php

declare(strict_types=1);

namespace TriCoreDb\Protocol;

/**
 * Optional protocol capabilities negotiated in HELLO.
 *
 * The client announces a bitmap; the server grants the intersection with what
 * it supports. A server too old to negotiate grants nothing (0).
 */
final class Features
{
    /** The server echoes a client-supplied `correlation_id`. */
    public const CORRELATION_ID = 1;

    /** The server binds `?` placeholders from a typed `params` array. */
    public const SERVER_PARAMS = 2;

    /** `BEGIN`/`COMMIT`/`ROLLBACK` as separate requests on one connection. */
    public const SESSION_TXN = 4;

    /** Every capability this build understands. */
    public const ALL = self::CORRELATION_ID | self::SERVER_PARAMS | self::SESSION_TXN;

    private function __construct()
    {
    }
}
