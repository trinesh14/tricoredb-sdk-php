<?php

declare(strict_types=1);

namespace TriCoreDb\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TriCoreDb\ErrorCode;
use TriCoreDb\Exception\ProtocolException;
use TriCoreDb\Protocol\Frame;
use TriCoreDb\Protocol\FrameTag;

final class FrameTest extends TestCase
{
    public function testAFrameIsASixByteHeaderAndItsPayload(): void
    {
        $frame = Frame::encode(FrameTag::REQUEST, '{"a":1}');

        self::assertSame("\x01\x02\x00\x00\x00\x07{\"a\":1}", $frame);
    }

    public function testAHeaderRoundTrips(): void
    {
        $header = substr(Frame::encode(FrameTag::RESPONSE, str_repeat('x', 70000)), 0, Frame::HEADER_SIZE);

        self::assertSame(['version' => 1, 'tag' => FrameTag::RESPONSE, 'length' => 70000], Frame::decodeHeader($header));
    }

    public function testAControlFrameAboveItsCeilingIsRefusedBeforeThePayloadIsRead(): void
    {
        self::assertProtocolError(
            ErrorCode::FRAME_TOO_LARGE,
            static fn () => Frame::decodeHeader(pack('CCN', 1, FrameTag::AUTH_OK, Frame::MAX_CONTROL_PAYLOAD + 1))
        );
    }

    public function testADataFrameAboveSixteenMebibytesIsRefused(): void
    {
        self::assertProtocolError(
            ErrorCode::FRAME_TOO_LARGE,
            static fn () => Frame::decodeHeader(pack('CCN', 1, FrameTag::RESPONSE, Frame::MAX_PAYLOAD + 1))
        );
    }

    public function testAnUnknownTagGetsTheTighterCeiling(): void
    {
        self::assertProtocolError(
            ErrorCode::FRAME_TOO_LARGE,
            static fn () => Frame::decodeHeader(pack('CCN', 1, 99, Frame::MAX_CONTROL_PAYLOAD + 1))
        );
    }

    public function testANewerFrameVersionIsRefusedRatherThanGuessedAt(): void
    {
        self::assertProtocolError(
            ErrorCode::FRAME_VERSION,
            static fn () => Frame::decodeHeader(pack('CCN', 2, FrameTag::RESPONSE, 0))
        );
    }

    public function testAShortHeaderIsRefused(): void
    {
        self::assertProtocolError(ErrorCode::PROTOCOL, static fn () => Frame::decodeHeader("\x01\x02"));
    }

    public function testThisClientWillNotSendMoreThanTheProtocolAllows(): void
    {
        self::assertProtocolError(
            ErrorCode::FRAME_TOO_LARGE,
            static fn () => Frame::encode(FrameTag::AUTH, str_repeat('x', Frame::MAX_CONTROL_PAYLOAD + 1))
        );
    }

    public function testTagsHaveReadableNames(): void
    {
        self::assertSame('HELLO_OK', FrameTag::name(FrameTag::HELLO_OK));
        self::assertSame('tag 42', FrameTag::name(42));
    }

    private static function assertProtocolError(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('expected a ProtocolException with code ' . $code);
        } catch (ProtocolException $e) {
            self::assertSame($code, $e->getErrorCode());
        }
    }
}
