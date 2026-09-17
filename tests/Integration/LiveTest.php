<?php

declare(strict_types=1);

namespace TriCoreDb\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TriCoreDb\Accumulator;
use TriCoreDb\Bytes;
use TriCoreDb\Client;
use TriCoreDb\Decimal;
use TriCoreDb\Exception\ServerException;
use TriCoreDb\Filter;
use TriCoreDb\GraphDirection;
use TriCoreDb\GroupKey;
use TriCoreDb\LlmSource;
use TriCoreDb\Protocol\Features;
use TriCoreDb\Stage;
use TriCoreDb\Tests\Support\LiveServer;

/**
 * What this client does against a real `tricore-server`. Without a server
 * binary these skip rather than fail.
 */
final class LiveTest extends TestCase
{
    private Client $db;

    protected function setUp(): void
    {
        $reason = LiveServer::skipReason();
        if ($reason !== null) {
            self::markTestSkipped($reason);
        }
        $this->db = LiveServer::shared()->connect();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testTheHandshakeGrantsTheCapabilitiesThisClientAsksFor(): void
    {
        self::assertNotEmpty($this->db->getSessionId());
        self::assertTrue($this->db->hasFeature(Features::SERVER_PARAMS));
        self::assertTrue($this->db->hasFeature(Features::SESSION_TXN));
        $this->db->ping();
        $this->db->adminPing();
        self::assertNotEmpty($this->db->adminStatus());
    }

    public function testValuesRoundTripThroughBoundParameters(): void
    {
        $table = self::unique('t');
        $this->db->execute("CREATE TABLE $table (id INT PRIMARY KEY, name TEXT, score DOUBLE, amount DECIMAL, payload BLOB, note TEXT)");
        $this->db->execute(
            "INSERT INTO $table (id, name, score, amount, payload, note) VALUES (?, ?, ?, ?, ?, ?)",
            [1, "ada \u{1F600}", 9.5, new Decimal('10.25'), new Bytes("\x00\x9f\x92\x96"), null]
        );

        $rows = $this->db->query("SELECT name, score, amount, note FROM $table WHERE id = ?", [1]);
        self::assertCount(1, $rows);
        self::assertSame("ada \u{1F600}", $rows->value(0, 'name'));
        self::assertSame(9.5, (float) $rows->value(0, 'score'));
        self::assertSame('10.25', $rows->value(0, 'amount'));
        // Cells are the server's own rendering, and a SQL NULL renders as the text NULL.
        self::assertSame('NULL', $rows->value(0, 'note'));
    }

    public function testAParameterIsAValueNeverPartOfTheStatement(): void
    {
        $table = self::unique('t');
        $this->db->execute("CREATE TABLE $table (id INT PRIMARY KEY, name TEXT)");
        $this->db->execute("INSERT INTO $table VALUES (?, ?)", [1, 'ada']);

        $found = $this->db->query("SELECT id FROM $table WHERE name = ?", ["ada'; DROP TABLE $table; --"]);
        self::assertCount(0, $found);
        self::assertCount(1, $this->db->query("SELECT id FROM $table"), 'the table is still there');
    }

    public function testAWriteReportsHowManyRowsItChanged(): void
    {
        $table = self::unique('t');
        $this->db->execute("CREATE TABLE $table (id INT PRIMARY KEY, n INT)");
        foreach ([1, 2, 3] as $i) {
            $this->db->execute("INSERT INTO $table VALUES (?, ?)", [$i, $i]);
        }

        self::assertSame(2, $this->db->execute("UPDATE $table SET n = ? WHERE id > ?", [0, 1])->rowsAffected());
    }

    public function testTransactionsCommitTogetherAndRollBackTogether(): void
    {
        $table = self::unique('t');
        $this->db->execute("CREATE TABLE $table (id INT PRIMARY KEY)");

        $this->db->begin();
        self::assertTrue($this->db->inTransaction());
        $this->db->execute("INSERT INTO $table VALUES (?)", [1]);
        $this->db->rollback();
        self::assertFalse($this->db->inTransaction());
        self::assertCount(0, $this->db->query("SELECT id FROM $table"));

        $this->db->transaction(static function (Client $tx) use ($table): void {
            $tx->execute("INSERT INTO $table VALUES (?)", [2]);
            $tx->execute("INSERT INTO $table VALUES (?)", [3]);
        });
        self::assertCount(2, $this->db->query("SELECT id FROM $table"));

        try {
            $this->db->transaction(static function (Client $tx) use ($table): void {
                $tx->execute("INSERT INTO $table VALUES (?)", [4]);
                throw new \DomainException('the caller changed their mind');
            });
            self::fail('the original exception must come back');
        } catch (\DomainException) {
        }
        self::assertFalse($this->db->inTransaction());
        self::assertCount(2, $this->db->query("SELECT id FROM $table"), 'the failed block left nothing behind');
    }

    public function testARefusedStatementLeavesTheConnectionUsable(): void
    {
        try {
            $this->db->query('SELECT * FROM ' . self::unique('missing'));
            self::fail('the table does not exist');
        } catch (ServerException $e) {
            self::assertNotNull($e->getErrorCode());
        }
        self::assertFalse($this->db->isClosed());
        self::assertCount(1, $this->db->query('SELECT 1'));
    }

    public function testCacheValuesAreBytesAndAMissIsNull(): void
    {
        $ns = self::unique('ns');
        self::assertNull($this->db->cacheGet($ns, 'k'));

        $this->db->cacheSet($ns, 'k', 'value', ttlMs: 60000);
        self::assertSame('value', $this->db->cacheGet($ns, 'k'));
        self::assertGreaterThan(0, $this->db->cacheTtl($ns, 'k'));

        $this->db->cacheSet($ns, 'bytes', "\x00\x9f\x92\x96");
        self::assertSame("\x00\x9f\x92\x96", $this->db->cacheGet($ns, 'bytes'), 'bytes that are not UTF-8 survive');

        $this->db->cacheSet($ns, 'empty', '');
        self::assertSame('', $this->db->cacheGet($ns, 'empty'), 'an empty value is not a miss');

        self::assertFalse($this->db->cacheSetNx($ns, 'k', 'other'));
        self::assertTrue($this->db->cacheDelete($ns, 'k'));
        self::assertFalse($this->db->cacheExists($ns, 'k'));
        self::assertSame(5, $this->db->cacheIncr($ns, 'n', 5));

        self::assertSame(2, $this->db->cacheRPush($ns, 'list', ['a', 'b']));
        self::assertSame(['a', 'b'], $this->db->cacheLRange($ns, 'list', 0, -1));
        self::assertSame('a', $this->db->cacheLPop($ns, 'list'));

        self::assertSame(2, $this->db->cacheSAdd($ns, 'set', ['x', 'y']));
        self::assertTrue($this->db->cacheSIsMember($ns, 'set', 'x'));

        // A numeric field name would become an integer key in PHP; it is still text.
        self::assertSame(2, $this->db->cacheHSet($ns, 'hash', ['name' => 'ada', '42' => 'answer']));
        self::assertSame('answer', $this->db->cacheHGet($ns, 'hash', '42'));
        self::assertSame([['42', 'answer'], ['name', 'ada']], $this->db->cacheHGetAll($ns, 'hash'));

        $id = $this->db->cacheXAdd($ns, 'stream', ['event' => 'created']);
        $entries = $this->db->cacheXRange($ns, 'stream');
        self::assertCount(1, $entries);
        self::assertSame($id, $entries[0]->id);
        self::assertSame(['event' => 'created'], $entries[0]->toArray());
    }

    public function testDocumentsAreInsertedFilteredAndAggregated(): void
    {
        $collection = self::unique('c');
        $this->db->docCreateCollection($collection);
        $this->db->docInsert($collection, ['name' => 'ada', 'city' => 'Pune', 'visits' => 5]);
        $this->db->docInsert($collection, ['name' => 'grace', 'city' => 'Pune', 'visits' => 2]);
        $id = $this->db->docInsert($collection, ['name' => 'alan', 'city' => 'Delhi', 'visits' => 9, 'tags' => []]);

        $found = $this->db->docFind($collection, Filter::and(Filter::eq('city', 'Pune'), Filter::gt('visits', 4)));
        self::assertCount(1, $found);
        self::assertSame('ada', $found[0]['name']);
        self::assertSame('alan', $this->db->docGet($collection, $id)['name'] ?? null);
        self::assertNull($this->db->docGet($collection, 'no-such-id'));

        $totals = $this->db->docAggregate($collection, [
            Stage::group(GroupKey::field('city'), Accumulator::sum('total', 'visits')),
            Stage::sort([['field' => 'total', 'descending' => true]]),
        ]);
        self::assertEquals(9, $totals[0]['total']);

        $outcome = $this->db->docUpdateOne($collection, $id, inc: ['visits' => 1]);
        self::assertNotEmpty($outcome);
        self::assertSame(10, $this->db->docGet($collection, $id)['visits']);
    }

    public function testVectorsAreSearchableAndFilterable(): void
    {
        $collection = self::unique('v');
        $this->db->vectorCreateCollection($collection, 3);
        $this->db->vectorUpsert($collection, 'a', [1, 0, 0], ['kind' => 'x']);
        $this->db->vectorUpsert($collection, 'b', [0, 1, 0], ['kind' => 'y']);
        $this->db->vectorUpsert($collection, 'c', [0, 0, 1]);

        $nearest = $this->db->vectorSearch($collection, [1, 0, 0], 1);
        self::assertSame('a', $nearest[0]['id']);

        $filtered = $this->db->vectorSearch($collection, [1, 0, 0], 3, ['kind' => 'y']);
        self::assertSame(['b'], array_column($filtered, 'id'));
        self::assertSame(3, $this->db->vectorDescribeCollection($collection)['dimension']);

        $this->expectException(ServerException::class);
        $this->db->vectorUpsert($collection, 'd', [1, 0]);
    }

    public function testGraphsWalkFromNodeToNode(): void
    {
        $graph = self::unique('g');
        $this->db->graphCreate($graph);
        $this->db->graphAddNode($graph, 'n1', ['Person'], ['name' => 'ada']);
        $this->db->graphAddNode($graph, 'n2');
        $this->db->graphAddNode($graph, 'n3');
        $this->db->graphAddEdge($graph, 'e1', 'n1', 'n2', 'KNOWS');
        $this->db->graphAddEdge($graph, 'e2', 'n2', 'n3', 'KNOWS', ['since' => 2020]);

        $neighbours = $this->db->graphNeighbors($graph, 'n1');
        self::assertSame(['n2'], array_column($neighbours, 'node_id'));
        self::assertSame(2, $this->db->graphDegree($graph, 'n2', GraphDirection::Both));

        $path = $this->db->graphShortestPath($graph, 'n1', 'n3');
        self::assertTrue($path['found']);
        self::assertSame(2, $path['hops']);
        self::assertFalse($this->db->graphShortestPath($graph, 'n3', 'n1')['found'], 'no path is an answer');
    }

    public function testAnLlmExportRendersTheCatalogue(): void
    {
        $table = self::unique('t');
        $this->db->execute("CREATE TABLE $table (id INT PRIMARY KEY, name TEXT)");
        $this->db->execute("INSERT INTO $table VALUES (?, ?)", [1, 'ada']);

        $schema = $this->db->llmSchema();
        self::assertIsString($schema);
        self::assertStringContainsString($table, $schema);

        $context = $this->db->llmContext([LlmSource::sql("SELECT id, name FROM $table")]);
        self::assertIsString($context);
        self::assertStringContainsString('ada', $context);
    }

    public function testASecondConnectionCanCancel(): void
    {
        $other = LiveServer::shared()->connect();
        // Nothing runs under this id, so the answer is zero rather than an error.
        self::assertSame(0, $other->cancel('no-such-request'));
        $other->close();
    }

    public function testClosingIsPromptAndFinal(): void
    {
        $started = microtime(true);
        $this->db->close();
        self::assertLessThan(1.0, microtime(true) - $started, 'the server answers CLOSE');
        self::assertTrue($this->db->isClosed());
        $this->db->close();
    }

    private static function unique(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(5));
    }
}
