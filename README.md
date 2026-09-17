# tricoredb-sdk-php

Official PHP client for [TriCoreDB](https://hub.docker.com/r/trinesh14/tricoredb):
SQL, documents, vectors, graphs and cache over one native connection.

[![Packagist](https://img.shields.io/packagist/v/tricoredb/tricoredb?cacheSeconds=86400)](https://packagist.org/packages/tricoredb/tricoredb)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4?logo=php&cacheSeconds=86400)](composer.json)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue?cacheSeconds=86400)](LICENSE)

- **No runtime dependencies.** PHP's own streams and `ext-json` are all it needs.
- **Server-side parameters.** Values never become part of the SQL text.
- **Exact values.** `Decimal` for money, `Bytes` for BLOBs, and a float that has
  already lost precision is refused rather than sent.
- **Transactions, TLS and mutual TLS.**

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Running a server](#running-a-server)
- [Quick start](#quick-start)
- [Connecting](#connecting)
- [SQL](#sql)
- [Parameters](#parameters)
- [Transactions](#transactions)
- [Cache](#cache)
- [Documents](#documents)
- [Vectors](#vectors)
- [Graphs](#graphs)
- [LLM context](#llm-context)
- [Admin](#admin)
- [Errors](#errors)
- [TLS](#tls)
- [Connections in PHP applications](#connections-in-php-applications)
- [Testing](#testing)

## Requirements

- PHP **8.1** or later, with `ext-json`; `ext-openssl` for TLS.
- A TriCoreDB server speaking protocol 1.0 (`tricore-server` 0.1.0-rc.1 or
  later). See [Running a server](#running-a-server).

## Installation

```bash
composer require tricoredb/tricoredb
```

## Running a server

```bash
docker run --rm -p 8427:8427 trinesh14/tricoredb:0.1.0-rc.1-r2
```

The image listens on 8427 and speaks the `tricore` protocol this package uses.
For credentials and configuration, see the image's own documentation.

## Quick start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use TriCoreDb\Client;

$db = Client::connect(host: '127.0.0.1', port: 8427, user: 'admin', secret: 'secret');

$db->execute('CREATE TABLE people (id INT PRIMARY KEY, name TEXT, score DOUBLE)');
$db->execute('INSERT INTO people VALUES (?, ?, ?)', [1, 'ada', 9.5]);

$rows = $db->query('SELECT name, score FROM people WHERE id = ?', [1]);
echo $rows->value(0, 'name'); // ada

$db->close();
```

## Connecting

```php
$db = Client::connect(
    host: '127.0.0.1',
    port: 8427,
    user: 'admin',
    secret: getenv('TRICORE_SECRET'),
    database: 'main',
    connectTimeout: 10.0,     // TCP connect, TLS and login
    readTimeout: 30.0,        // each reply afterwards; null waits as long as the statement runs
    requestTimeoutMs: 5000,   // a deadline the server applies
);
```

When `readTimeout` fires, the connection is closed: the late reply would
otherwise be read as the answer to the next request. To make the **server** stop
the work, use `requestTimeoutMs`.

The handshake negotiates capabilities:

```php
use TriCoreDb\Protocol\Features;

$db->hasFeature(Features::SERVER_PARAMS); // ? placeholders bound by the server
$db->hasFeature(Features::SESSION_TXN);   // begin/commit as separate requests
```

A call that needs a capability the server did not grant throws **before
anything is sent**, rather than falling back to something weaker.

## SQL

```php
$rows = $db->query('SELECT id, name FROM people WHERE score > ?', [8]);

count($rows);             // how many rows
$rows->rows[0][1];        // by position
$rows->value(0, 'name');  // by column name
$rows->toAssoc();         // [['id' => '1', 'name' => 'ada']]
foreach ($rows as $row) { /* ... */ }
```

Cells are the server's own text rendering of each value, so a number arrives as
its digits and a SQL `NULL` arrives as the text `NULL`.

Writes go through `execute`, which reports how many rows changed:

```php
$db->execute('UPDATE people SET score = ? WHERE id = ?', [9.9, 1])->rowsAffected(); // 1
```

## Parameters

Values are bound by the server. This driver never writes them into the statement
text, because escaping and binding are not the same guarantee:

```php
$db->query('SELECT id FROM people WHERE name = ?', ["ada'; DROP TABLE people; --"]);
// finds nothing, drops nothing
```

| PHP | On the wire |
| --- | --- |
| `null`, `bool`, `int` | themselves |
| `float` | a JSON number; NaN, the infinities and floats that have already lost an integer are refused |
| `string` | text — PHP strings do not say whether they hold bytes |
| `Bytes` | `0x…` hex, for a BLOB column |
| `Decimal` | plain digits, so nothing is rounded (`new Decimal('10.25')`) |
| `DateTimeInterface` | ISO-8601 with microseconds and offset |
| `BackedEnum` | its value |

Anything else is refused by name, with the parameter's position. For a JSON
column, `json_encode` the value yourself.

## Transactions

```php
$db->transaction(function (Client $tx): void {
    $tx->execute('UPDATE accounts SET balance = balance - ? WHERE id = ?', [100, 1]);
    $tx->execute('UPDATE accounts SET balance = balance + ? WHERE id = ?', [100, 2]);
});
```

If the callback throws, the transaction is rolled back and the original
exception is rethrown. `begin()`, `commit()` and `rollback()` are there too, and
`inTransaction()` says where you are.

Session transactions need `SESSION_TXN`. Without it, send the whole script in one
request:

```php
$db->execute('BEGIN; UPDATE ...; COMMIT;');
```

## Cache

Cache values are bytes, and any PHP string is sent as it is.

```php
$db->cacheSet('sessions', 'abc', 'ada', ttlMs: 1_800_000);
$db->cacheGet('sessions', 'abc');   // 'ada', or null on a miss
```

A miss is `null`; a stored empty value is `''`. Redis-style calls cover lists,
sets, hashes and streams: `cacheRPush`, `cacheLRange`, `cacheSAdd`, `cacheHSet`,
`cacheHGetAll`, `cacheXAdd`, `cacheXRange` and the rest.

`cacheHGetAll` returns `[field, value]` pairs rather than a map, because PHP
would turn a numeric field name into an integer key.

## Documents

```php
use TriCoreDb\Accumulator;
use TriCoreDb\Filter;
use TriCoreDb\GroupKey;
use TriCoreDb\Stage;

$db->docCreateCollection('people');
$id = $db->docInsert('people', ['name' => 'ada', 'city' => 'Pune', 'visits' => 5]);

$found = $db->docFind('people', Filter::and(Filter::eq('city', 'Pune'), Filter::gt('visits', 4)));
$one = $db->docGet('people', $id);   // null when absent

$totals = $db->docAggregate('people', [
    Stage::group(GroupKey::field('city'), Accumulator::sum('total', 'visits')),
    Stage::sort([['field' => 'total', 'descending' => true]]),
]);

$db->docUpdateOne('people', $id, set: ['city' => 'Mumbai'], inc: ['visits' => 1]);
```

## Vectors

```php
$db->vectorCreateCollection('embeddings', 3);
$db->vectorUpsert('embeddings', 'a', [1, 0, 0], ['kind' => 'doc']);

$nearest = $db->vectorSearch('embeddings', [1, 0, 0], 5);
$nearest[0]['id'];     // 'a'
$nearest[0]['score'];  // higher is closer, for every metric
```

A vector whose length differs from the collection's dimension is refused, not
padded or truncated.

## Graphs

```php
use TriCoreDb\GraphDirection;

$db->graphCreate('social');
$db->graphAddNode('social', 'n1', ['Person'], ['name' => 'ada']);
$db->graphAddEdge('social', 'e1', 'n1', 'n2', 'KNOWS');

$db->graphNeighbors('social', 'n1');
$db->graphDegree('social', 'n1', GraphDirection::Both);
$db->graphShortestPath('social', 'n1', 'n3');   // ['found' => false, ...] when there is none
```

## LLM context

```php
use TriCoreDb\LlmSource;

$schema = $db->llmSchema();                        // TOON text
$bundle = $db->llmContext([
    LlmSource::sql('SELECT id, name FROM people'),
    LlmSource::documentFind('notes', limit: 50),
]);
```

## Admin

```php
$db->adminPing();    // a round trip through the whole pipeline
$db->adminStatus();  // what the server says about itself
$db->ping();         // the socket alone
```

## Errors

Every exception extends `TriCoreDb\Exception\TriCoreException`. Branch on
`getErrorCode()`, never on the message:

```php
use TriCoreDb\Exception\ConnectionException;
use TriCoreDb\Exception\ServerException;

try {
    $db->execute('INSERT INTO t VALUES (1)');
} catch (ServerException $e) {
    if ($e->isRedirect()) {
        // This node is not the leader. getLeaderHint() names the one that is,
        // when the cluster knows. This driver never follows it by itself: the
        // address may not be reachable from here, a new connection has to log
        // in again, and an open transaction cannot move nodes.
        $leader = $e->getLeaderHint();
    }
} catch (ConnectionException $e) {
    // the socket failed or was closed; this connection is unusable
}
```

| Exception | What happened |
| --- | --- |
| `ServerException` | the server processed the request and refused it; the connection is fine |
| `AuthenticationException` | the credentials were refused |
| `ProtocolException` | an ERROR frame, a refused handshake, or a stream that cannot be trusted |
| `ConnectionException` | the socket failed or was closed |
| `TimeoutException` | no reply within the read timeout; the connection is closed |
| `FeatureNotGrantedException` | a capability the server did not grant; nothing was sent |
| `InvalidValueException` | a value with no exact wire form; nothing was sent |

## TLS

```php
use TriCoreDb\TlsOptions;

$db = Client::connect(
    host: 'db.example.com',
    user: 'admin',
    secret: getenv('TRICORE_SECRET'),
    tls: new TlsOptions(caFile: '/etc/tricore/ca.pem'),
);
```

Mutual TLS needs both halves of the identity:

```php
new TlsOptions(
    caFile: '/etc/tricore/ca.pem',
    clientCertFile: '/etc/tricore/client.pem',
    clientKeyFile: '/etc/tricore/client.key',
);
```

Certificates are verified by default, with TLS 1.2 or later. Without `caFile`,
PHP's configured trust store is used. `dangerAcceptInvalidCerts: true` turns
verification off and is for a development server only.

## Connections in PHP applications

A connection lives as long as the PHP process that opened it. Under PHP-FPM or
`mod_php` that is one request: connect when you need the database and let the
connection close at the end. In a long-running worker (Swoole, RoadRunner,
FrankenPHP worker mode, a queue consumer) keep one connection per worker, and
reconnect after a `ConnectionException` or `TimeoutException`.

## Testing

```bash
composer install
composer test
```

The unit tests and the scripted-peer tests need no server: a peer run as its own
PHP process plays the answers a real cluster would send, including a
`not_leader` refusal and a frame that declares more bytes than it sends. The live
tests start their own `tricore-server` — point `TRICORE_SERVER_BIN` at the
binary, and without one they are **skipped** rather than failed.

## License

[Apache License 2.0](LICENSE)
