<?php

declare(strict_types=1);

namespace TriCoreDb\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TriCoreDb\ErrorCode;
use TriCoreDb\Exception\AuthenticationException;
use TriCoreDb\Exception\ConnectionException;
use TriCoreDb\Exception\FeatureNotGrantedException;
use TriCoreDb\Exception\ProtocolException;
use TriCoreDb\Exception\ServerException;
use TriCoreDb\Exception\TimeoutException;
use TriCoreDb\Protocol\Features;
use TriCoreDb\Protocol\Frame;
use TriCoreDb\Protocol\FrameTag;
use TriCoreDb\Tests\Support\ScriptedPeer;

/**
 * How this client reads the protocol, proved without a server. These always run.
 */
final class ScriptedPeerTest extends TestCase
{
    public function testANotLeaderRefusalIsTypedAndCarriesTheLeaderAddress(): void
    {
        $peer = ScriptedPeer::start([ScriptedPeer::response([
            'request_id' => 'r1',
            'status' => 'error',
            'data' => ['Message' => 'not the raft leader'],
            'diagnostics' => ['error_code' => 'not_leader', 'leader_hint' => '10.9.9.7:8427'],
        ])]);
        $db = $peer->connect();

        try {
            $db->execute('INSERT INTO t VALUES (1)');
            self::fail('a follower must refuse the write');
        } catch (ServerException $e) {
            self::assertSame(ErrorCode::NOT_LEADER, $e->getErrorCode());
            self::assertTrue($e->isRedirect());
            self::assertSame('10.9.9.7:8427', $e->getLeaderHint());
            self::assertStringContainsString('10.9.9.7:8427', $e->getMessage());
        }
        self::assertFalse($db->isClosed(), 'a refusal leaves the connection usable');
        $db->close();
    }

    public function testMidElectionThereIsACodeButNoAddress(): void
    {
        $peer = ScriptedPeer::start([ScriptedPeer::response([
            'request_id' => 'r1',
            'status' => 'error',
            'data' => ['Message' => 'not the raft leader'],
            'diagnostics' => ['error_code' => 'not_leader'],
        ])]);
        $db = $peer->connect();

        try {
            $db->execute('INSERT INTO t VALUES (1)');
            self::fail('the write must be refused');
        } catch (ServerException $e) {
            self::assertTrue($e->isRedirect());
            self::assertNull($e->getLeaderHint(), 'an absent hint means the destination is unknown');
        }
        $db->close();
    }

    public function testAnAuthOkFrameCarryingOkFalseIsStillARefusal(): void
    {
        $peer = ScriptedPeer::startRaw([
            ScriptedPeer::reply(FrameTag::HELLO_OK, ['ok' => true, 'message' => 'ok', 'features' => 7]),
            // The tag names the answer's shape; the body is the verdict.
            ScriptedPeer::reply(FrameTag::AUTH_OK, ['ok' => false, 'message' => 'bad password']),
        ]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('bad password');
        $peer->connect();
    }

    public function testARefusedHandshakeIsReportedAsOne(): void
    {
        $peer = ScriptedPeer::startRaw([ScriptedPeer::reply(FrameTag::HELLO_OK, [
            'ok' => false,
            'message' => 'unsupported protocol version',
            'code' => 'protocol_version',
        ])]);

        try {
            $peer->connect();
            self::fail('the handshake must be refused');
        } catch (ProtocolException $e) {
            self::assertSame(ErrorCode::PROTOCOL_VERSION, $e->getErrorCode());
            self::assertStringContainsString('unsupported protocol', $e->getMessage());
        }
    }

    public function testADeclaredPayloadAboveTheCeilingIsRefusedBeforeItIsRead(): void
    {
        // A control frame claiming 64 KiB + 1 bytes, with none of them sent. A
        // client that trusted the length would allocate it and wait for ever.
        $peer = ScriptedPeer::start([
            ScriptedPeer::raw(pack('CCN', Frame::VERSION, FrameTag::AUTH_OK, Frame::MAX_CONTROL_PAYLOAD + 1)),
        ]);
        $db = $peer->connect();

        try {
            $db->ping();
            self::fail('the frame must be refused');
        } catch (ProtocolException $e) {
            self::assertSame(ErrorCode::FRAME_TOO_LARGE, $e->getErrorCode());
        }
        self::assertTrue($db->isClosed(), 'a stream that cannot be resynchronised is dropped');
    }

    public function testAPeerThatHangsUpMidFrameDoesNotLeaveTheClientWaiting(): void
    {
        $peer = ScriptedPeer::start([
            ScriptedPeer::raw(pack('CCN', Frame::VERSION, FrameTag::RESPONSE, 10) . '{'),
            ScriptedPeer::hangUp(),
        ]);
        $db = $peer->connect();

        try {
            $db->execute('SELECT 1');
            self::fail('the peer went away mid-frame');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('mid-frame', $e->getMessage());
        }
        self::assertTrue($db->isClosed());
    }

    public function testAReplyThatNeverArrivesEndsAtTheReadTimeout(): void
    {
        $peer = ScriptedPeer::start([ScriptedPeer::silence()]);
        $db = $peer->connect(readTimeout: 0.2);

        $started = microtime(true);
        try {
            $db->execute('SELECT 1');
            self::fail('no reply was ever sent');
        } catch (TimeoutException) {
            self::assertLessThan(3.0, microtime(true) - $started, 'it did not wait');
        }
        self::assertTrue($db->isClosed(), 'the reply may still arrive, so the socket cannot be reused');
    }

    public function testAStatusThisClientDoesNotKnowIsTreatedAsAFailure(): void
    {
        $peer = ScriptedPeer::start([ScriptedPeer::response([
            'request_id' => 'r1',
            'status' => 'not_implemented',
            'data' => ['Message' => 'Cache::XGroup is refused in V1'],
        ])]);
        $db = $peer->connect();

        try {
            $db->request(['Cache' => ['XGroup' => new \stdClass()]]);
            self::fail('anything but ok is a failure');
        } catch (ServerException $e) {
            self::assertSame('not_implemented', $e->getStatus());
            self::assertStringContainsString('XGroup', $e->getMessage());
        }
        $db->close();
    }

    public function testAServerThatGrantedNothingMakesTheClientRefuseBeforeSending(): void
    {
        // An older server grants no capabilities at all.
        $peer = ScriptedPeer::start([ScriptedPeer::silence()], features: 0);
        $db = $peer->connect();
        self::assertSame(0, $db->getGrantedFeatures());
        self::assertFalse($db->hasFeature(Features::SERVER_PARAMS));

        try {
            $db->query('SELECT * FROM t WHERE id = ?', [1]);
            self::fail('binding needs the capability');
        } catch (FeatureNotGrantedException $e) {
            self::assertStringContainsString('SERVER_PARAMS', $e->getMessage());
        }
        self::assertFalse($db->isClosed(), 'nothing was sent, so the connection is untouched');

        try {
            $db->begin();
            self::fail('a session transaction needs the capability');
        } catch (FeatureNotGrantedException $e) {
            self::assertStringContainsString('SESSION_TXN', $e->getMessage());
        }
    }

    public function testWarningsAndDiagnosticsReachTheCallerOnASuccessfulResponse(): void
    {
        $peer = ScriptedPeer::start([ScriptedPeer::response([
            'request_id' => 'r1',
            'status' => 'ok',
            'data' => ['Message' => 'done'],
            'diagnostics' => ['route' => 'local', 'warnings' => ['shard 2 was unreachable']],
        ])]);
        $db = $peer->connect();

        $response = $db->request(['Admin' => 'Ping']);
        self::assertTrue($response->isOk());
        self::assertSame(['shard 2 was unreachable'], $response->getWarnings());
        $db->close();
    }

    public function testTheRequestEnvelopeCarriesTheDatabaseAndAUniqueId(): void
    {
        $peer = ScriptedPeer::start([ScriptedPeer::response([
            'request_id' => 'r1',
            'status' => 'ok',
            'data' => ['Rows' => ['columns' => ['n'], 'rows' => [['1']]]],
        ])]);
        $db = $peer->connect();
        $db->setDatabase('reporting');

        $rows = $db->query('SELECT 1');
        self::assertSame([['1']], $rows->rows);
        self::assertStringStartsWith('php-', (string) $db->getLastRequestId());
        self::assertSame('reporting', $db->getDatabase());
        $db->close();
    }

    public function testAValueThatCannotBeSentLeavesTheConnectionUsable(): void
    {
        $peer = ScriptedPeer::start([ScriptedPeer::response([
            'request_id' => 'r1',
            'status' => 'ok',
            'data' => ['Rows' => ['columns' => ['n'], 'rows' => [['1']]]],
        ])]);
        $db = $peer->connect();

        try {
            $db->query('SELECT ?', [NAN]);
            self::fail('NaN has no SQL value');
        } catch (\TriCoreDb\Exception\InvalidValueException $e) {
            self::assertStringContainsString('parameter #1', $e->getMessage());
        }
        self::assertFalse($db->isClosed(), 'nothing was written, so the stream is still in step');
        self::assertCount(1, $db->query('SELECT 1'));
        $db->close();
    }
}
