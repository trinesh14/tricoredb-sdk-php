<?php

declare(strict_types=1);

namespace TriCoreDb\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TriCoreDb\Exception\ProtocolException;
use TriCoreDb\Exception\ServerException;
use TriCoreDb\Response;
use TriCoreDb\Rows;

final class ResponseTest extends TestCase
{
    public function testAnOkResponseCarriesItsDataAndDiagnostics(): void
    {
        $response = Response::fromWire([
            'request_id' => 'r1',
            'status' => 'ok',
            'data' => ['Json' => ['rows_affected' => 3]],
            'diagnostics' => ['warnings' => ['shard 2 was unreachable']],
        ]);

        self::assertTrue($response->isOk());
        self::assertSame('r1', $response->requestId);
        self::assertSame('Json', $response->dataKind());
        self::assertSame(3, $response->rowsAffected());
        self::assertSame(['shard 2 was unreachable'], $response->getWarnings());
    }

    public function testACacheMissIsNullNotAnEmptyValue(): void
    {
        $miss = Response::fromWire(['status' => 'ok', 'data' => ['CacheValue' => null]]);
        $empty = Response::fromWire(['status' => 'ok', 'data' => ['CacheValue' => []]]);

        self::assertNull($miss->cacheValue());
        self::assertSame('', $empty->cacheValue());
    }

    public function testAskingForTheWrongVariantIsAProtocolFailure(): void
    {
        $response = Response::fromWire(['status' => 'ok', 'data' => 'Empty']);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('expected Rows for query, got Empty');
        $response->rows('query');
    }

    public function testAPayloadThatIsNotAResponseIsRefused(): void
    {
        $this->expectException(ProtocolException::class);
        Response::fromWire('surprise');
    }

    public function testNotLeaderNamesTheLeaderAndEndsAnOpenTransaction(): void
    {
        $response = Response::fromWire([
            'status' => 'error',
            'data' => ['Message' => 'not the raft leader'],
            'diagnostics' => ['error_code' => 'not_leader', 'leader_hint' => '10.9.9.7:8427'],
        ]);

        $error = ServerException::fromResponse($response, true);
        self::assertTrue($error->isRedirect());
        self::assertSame('10.9.9.7:8427', $error->getLeaderHint());
        self::assertStringContainsString('10.9.9.7:8427', $error->getMessage());
        self::assertStringContainsString('transaction is over', $error->getMessage());
        self::assertSame($response, $error->getResponse());
    }

    public function testMidElectionTheMessageSaysToWait(): void
    {
        $response = Response::fromWire([
            'status' => 'error',
            'data' => ['Message' => 'not the raft leader'],
            'diagnostics' => ['error_code' => 'not_leader'],
        ]);

        $error = ServerException::fromResponse($response, false);
        self::assertNull($error->getLeaderHint());
        self::assertStringContainsString('wait and try again', $error->getMessage());
    }

    public function testRowsAreAddressableByPositionAndByName(): void
    {
        $rows = new Rows(['id', 'name'], [['1', 'ada'], ['2', 'NULL']]);

        self::assertCount(2, $rows);
        self::assertSame('ada', $rows->value(0, 'name'));
        self::assertSame('NULL', $rows->value(1, 'name'), 'a SQL NULL arrives as the text NULL');
        self::assertNull($rows->value(0, 'missing'));
        self::assertNull($rows->value(9, 'name'));
        self::assertSame([['id' => '1', 'name' => 'ada'], ['id' => '2', 'name' => 'NULL']], $rows->toAssoc());
        self::assertSame([['1', 'ada'], ['2', 'NULL']], iterator_to_array($rows));
    }
}
