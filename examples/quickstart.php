<?php

declare(strict_types=1);

// A tour of the package against a running server.
//
//   docker run --rm -p 8427:8427 trinesh14/tricoredb:0.1.0-rc.1-r2
//   php examples/quickstart.php

require __DIR__ . '/../vendor/autoload.php';

use TriCoreDb\Client;
use TriCoreDb\Decimal;
use TriCoreDb\Exception\ServerException;
use TriCoreDb\Filter;

$db = Client::connect(
    host: getenv('TRICORE_HOST') ?: '127.0.0.1',
    port: (int) (getenv('TRICORE_PORT') ?: 8427),
    user: getenv('TRICORE_USER') ?: 'admin',
    secret: getenv('TRICORE_SECRET') ?: 'pw',
);
printf("connected: session %s\n", $db->getSessionId());

$table = 'people_' . bin2hex(random_bytes(3));
$db->execute("CREATE TABLE $table (id INT PRIMARY KEY, name TEXT, balance DECIMAL)");
$db->execute("INSERT INTO $table VALUES (?, ?, ?)", [1, 'ada', new Decimal('100.50')]);
print_r($db->query("SELECT name, balance FROM $table WHERE id = ?", [1])->toAssoc());

// An injection attempt is a value, so it matches nothing and drops nothing.
$found = $db->query("SELECT id FROM $table WHERE name = ?", ["ada'; DROP TABLE $table; --"]);
printf("injection matched %d rows; the table is still here\n", count($found));

// Either both writes land, or neither does.
$db->transaction(static function (Client $tx) use ($table): void {
    $tx->execute("INSERT INTO $table VALUES (?, ?, ?)", [2, 'grace', new Decimal('20')]);
    $tx->execute("INSERT INTO $table VALUES (?, ?, ?)", [3, 'alan', new Decimal('30')]);
});
printf("after commit: %d rows\n", count($db->query("SELECT id FROM $table")));

// Cache, documents and vectors share the connection.
$db->cacheSet('demo', 'greeting', 'hello', ttlMs: 60_000);
printf("cached: %s\n", $db->cacheGet('demo', 'greeting'));

$notes = 'notes_' . bin2hex(random_bytes(3));
$db->docCreateCollection($notes);
$db->docInsert($notes, ['title' => 'first', 'tags' => ['a', 'b']]);
printf("documents: %d\n", count($db->docFind($notes, Filter::contains('tags', 'a'))));

$vectors = 'vectors_' . bin2hex(random_bytes(3));
$db->vectorCreateCollection($vectors, 3);
$db->vectorUpsert($vectors, 'a', [1, 0, 0]);
printf("nearest: %s\n", $db->vectorSearch($vectors, [1, 0, 0], 1)[0]['id']);

// A refusal is typed, and the connection stays usable.
try {
    $db->query('SELECT * FROM no_such_table');
} catch (ServerException $e) {
    printf("refused with code %s; still open: %s\n", $e->getErrorCode(), $db->isClosed() ? 'no' : 'yes');
}

$db->close();
