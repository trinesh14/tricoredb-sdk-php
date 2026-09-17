<?php

declare(strict_types=1);

namespace TriCoreDb\Protocol;

use TriCoreDb\ErrorCode;
use TriCoreDb\Exception\InvalidValueException;
use TriCoreDb\Exception\ProtocolException;

/**
 * Frame layout: `version:u8 | tag:u8 | payload_len:u32 big-endian | payload`.
 */
final class Frame
{
    /** The frame-header format version this build writes. */
    public const VERSION = 1;

    /** The highest frame-header format version this build can read. */
    public const MAX_SUPPORTED_VERSION = 1;

    /** Bytes in a frame header. */
    public const HEADER_SIZE = 6;

    /** Payload ceiling for REQUEST and RESPONSE frames (16 MiB). */
    public const MAX_PAYLOAD = 16 * 1024 * 1024;

    /** Payload ceiling for every other frame (64 KiB). */
    public const MAX_CONTROL_PAYLOAD = 64 * 1024;

    private function __construct()
    {
    }

    /**
     * The payload ceiling for one tag. An unknown tag takes the tighter one.
     *
     * @param int $tag The frame tag.
     * @return int The maximum payload length in bytes.
     */
    public static function maxPayloadFor(int $tag): int
    {
        return $tag === FrameTag::REQUEST || $tag === FrameTag::RESPONSE
            ? self::MAX_PAYLOAD
            : self::MAX_CONTROL_PAYLOAD;
    }

    /**
     * Encode one frame.
     *
     * @param int $tag The frame tag (0-255).
     * @param string $payload The payload bytes.
     * @return string The header followed by the payload.
     * @throws ProtocolException With code `frame_too_large` when the payload exceeds the tag's ceiling.
     */
    public static function encode(int $tag, string $payload): string
    {
        if ($tag < 0 || $tag > 255) {
            throw new InvalidValueException(sprintf('frame tag %d does not fit in one byte', $tag));
        }
        $length = strlen($payload);
        $limit = self::maxPayloadFor($tag);
        if ($length > $limit) {
            throw new ProtocolException(
                sprintf(
                    'refusing to send a %d-byte %s payload; the protocol caps that frame at %d bytes',
                    $length,
                    FrameTag::name($tag),
                    $limit
                ),
                ErrorCode::FRAME_TOO_LARGE
            );
        }

        return pack('CCN', self::VERSION, $tag, $length) . $payload;
    }

    /**
     * Decode and validate a frame header before any payload byte is read.
     *
     * @param string $header Exactly six bytes.
     * @return array{version: int, tag: int, length: int}
     * @throws ProtocolException With code `frame_version` or `frame_too_large`.
     */
    public static function decodeHeader(string $header): array
    {
        if (strlen($header) !== self::HEADER_SIZE) {
            throw new ProtocolException(
                sprintf('a frame header is %d bytes, got %d', self::HEADER_SIZE, strlen($header)),
                ErrorCode::PROTOCOL
            );
        }
        /** @var array{version: int, tag: int, length: int} $parts */
        $parts = unpack('Cversion/Ctag/Nlength', $header);
        if ($parts['version'] > self::MAX_SUPPORTED_VERSION) {
            throw new ProtocolException(
                sprintf(
                    'frame header version %d is newer than this driver can read (max %d)',
                    $parts['version'],
                    self::MAX_SUPPORTED_VERSION
                ),
                ErrorCode::FRAME_VERSION
            );
        }
        $limit = self::maxPayloadFor($parts['tag']);
        if ($parts['length'] > $limit) {
            throw new ProtocolException(
                sprintf(
                    'frame %s declares a %d-byte payload, above its %d-byte limit; refusing to buffer it',
                    FrameTag::name($parts['tag']),
                    $parts['length'],
                    $limit
                ),
                ErrorCode::FRAME_TOO_LARGE
            );
        }

        return $parts;
    }
}
