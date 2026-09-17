<?php

declare(strict_types=1);

namespace TriCoreDb\Protocol;

/**
 * Frame tags of the `tricore` wire protocol.
 *
 * A request is not answered with its own tag: HELLO is answered by HELLO_OK
 * (8), AUTH by AUTH_OK (9), CANCEL by CANCEL_OK (12).
 */
final class FrameTag
{
    public const HELLO = 0;
    public const AUTH = 1;
    public const REQUEST = 2;
    public const RESPONSE = 3;
    public const PING = 4;
    public const PONG = 5;
    public const ERROR = 6;
    public const CLOSE = 7;
    public const HELLO_OK = 8;
    public const AUTH_OK = 9;
    public const BYE = 10;
    public const CANCEL = 11;
    public const CANCEL_OK = 12;

    private const NAMES = [
        self::HELLO => 'HELLO',
        self::AUTH => 'AUTH',
        self::REQUEST => 'REQUEST',
        self::RESPONSE => 'RESPONSE',
        self::PING => 'PING',
        self::PONG => 'PONG',
        self::ERROR => 'ERROR',
        self::CLOSE => 'CLOSE',
        self::HELLO_OK => 'HELLO_OK',
        self::AUTH_OK => 'AUTH_OK',
        self::BYE => 'BYE',
        self::CANCEL => 'CANCEL',
        self::CANCEL_OK => 'CANCEL_OK',
    ];

    private function __construct()
    {
    }

    /**
     * A readable name for a tag, for error messages.
     *
     * @param int $tag The numeric tag.
     * @return string The tag's name, or `tag <n>` for a tag this build does not know.
     */
    public static function name(int $tag): string
    {
        return self::NAMES[$tag] ?? sprintf('tag %d', $tag);
    }
}
