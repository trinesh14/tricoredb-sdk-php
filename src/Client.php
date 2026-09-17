<?php

declare(strict_types=1);

namespace TriCoreDb;

use TriCoreDb\Exception\AuthenticationException;
use TriCoreDb\Exception\FeatureNotGrantedException;
use TriCoreDb\Exception\InvalidValueException;
use TriCoreDb\Exception\ProtocolException;
use TriCoreDb\Exception\ServerException;
use TriCoreDb\Exception\TriCoreException;
use TriCoreDb\Internal\Enums;
use TriCoreDb\Internal\Json;
use TriCoreDb\Internal\Params;
use TriCoreDb\Internal\Wire;
use TriCoreDb\Protocol\Features;
use TriCoreDb\Protocol\FrameTag;
use TriCoreDb\Protocol\Transport;

/**
 * One connection to a TriCoreDB server over the native `tricore` protocol.
 *
 * A connection is a single request/response stream, and every call blocks
 * until its reply has been read.
 *
 * ```php
 * $db = Client::connect(host: '127.0.0.1', user: 'admin', secret: getenv('TRICORE_SECRET'));
 * $db->execute('INSERT INTO t VALUES (?, ?)', [1, 'ada']);
 * $rows = $db->query('SELECT name FROM t WHERE id = ?', [1]);
 * $db->close();
 * ```
 */
final class Client
{
    /** The port a TriCoreDB server listens on by default. */
    public const DEFAULT_PORT = 8427;

    /** The database used when a call does not name one. */
    public const DEFAULT_DATABASE = 'main';

    private const CLOSE_TIMEOUT_SECONDS = 2.0;

    private string $database;

    private ?int $requestTimeoutMs = null;

    private int $grantedFeatures = 0;

    private ?string $sessionId = null;

    private ?string $lastRequestId = null;

    private int $requestCounter = 0;

    private string $requestPrefix;

    private bool $transactionOpen = false;

    private function __construct(private readonly Transport $transport, string $database)
    {
        $this->database = $database;
        $this->requestPrefix = sprintf('php-%x-%s', getmypid() ?: 0, bin2hex(random_bytes(4)));
    }

    /**
     * Connect, shake hands and, when a user is given, authenticate.
     *
     * @param string $host Host name or IP address.
     * @param int $port TCP port.
     * @param string|null $user User name; null skips authentication.
     * @param string $secret Password or token, sent as its bytes.
     * @param string $database The database used when a call does not name one.
     * @param float $connectTimeout Seconds covering the TCP connect, TLS, HELLO and AUTH.
     * @param float|null $readTimeout Seconds to wait for each later reply; null waits for as long as the statement runs.
     * @param int|null $requestTimeoutMs A deadline the server applies to every request.
     * @param TlsOptions|null $tls TLS settings, or null for plaintext.
     * @param string|null $clientName How this client names itself in the handshake.
     * @param int $features Capabilities to ask for; see {@see Features}.
     * @throws TriCoreException When the connection, handshake or login fails.
     */
    public static function connect(
        string $host = '127.0.0.1',
        int $port = self::DEFAULT_PORT,
        ?string $user = null,
        string $secret = '',
        string $database = self::DEFAULT_DATABASE,
        float $connectTimeout = 10.0,
        ?float $readTimeout = null,
        ?int $requestTimeoutMs = null,
        ?TlsOptions $tls = null,
        ?string $clientName = null,
        int $features = Features::ALL
    ): self {
        $transport = Transport::open($host, $port, $connectTimeout, $tls);
        $client = new self($transport, $database);
        try {
            $transport->setReadTimeout($connectTimeout > 0 ? $connectTimeout : null);
            $client->handshake($clientName ?? 'tricoredb-php/' . Version::VERSION, $features);
            if ($user !== null) {
                $client->authenticate($user, $secret);
            }
        } catch (\Throwable $e) {
            $transport->close();

            throw $e;
        }
        $client->setReadTimeout($readTimeout);
        $client->setRequestTimeoutMs($requestTimeoutMs);

        return $client;
    }

    public function __destruct()
    {
        $this->transport->close();
    }

    // ---- connection state ----------------------------------------------------

    /** The capability bitmap the server granted; see {@see Features}. */
    public function getGrantedFeatures(): int
    {
        return $this->grantedFeatures;
    }

    /** Whether the server granted a capability from {@see Features}. */
    public function hasFeature(int $feature): bool
    {
        return ($this->grantedFeatures & $feature) === $feature;
    }

    /** The session id the server issued, when this connection authenticated. */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /** The id of the most recent request; pass it to {@see Client::cancel()} on another connection. */
    public function getLastRequestId(): ?string
    {
        return $this->lastRequestId;
    }

    /** The database used when a call does not name one. */
    public function getDatabase(): string
    {
        return $this->database;
    }

    /** Change the database used when a call does not name one. */
    public function setDatabase(string $database): void
    {
        $this->database = $database;
    }

    /**
     * Bound each reply by `$seconds`; null waits for as long as the statement runs.
     *
     * When the bound is hit the connection is closed, because the late reply
     * would otherwise be read as the answer to the next request.
     */
    public function setReadTimeout(?float $seconds): void
    {
        $this->transport->setReadTimeout($seconds);
    }

    /** Ask the server to give up on each request after `$milliseconds`; null leaves it to the server. */
    public function setRequestTimeoutMs(?int $milliseconds): void
    {
        if ($milliseconds !== null && $milliseconds <= 0) {
            throw new InvalidValueException('a request timeout must be a positive number of milliseconds');
        }
        $this->requestTimeoutMs = $milliseconds;
    }

    /** Whether this connection can no longer be used. */
    public function isClosed(): bool
    {
        return !$this->transport->isOpen();
    }

    /** Whether a session transaction is open. */
    public function inTransaction(): bool
    {
        return $this->transactionOpen && !$this->isClosed();
    }

    // ---- control frames ------------------------------------------------------

    /**
     * Exchange PING and PONG. This never reaches a module; see
     * {@see Client::adminPing()} for a round trip through the whole pipeline.
     */
    public function ping(): void
    {
        [$tag] = $this->exchange(FrameTag::PING, null);
        if ($tag !== FrameTag::PONG) {
            $this->failStream(sprintf('expected PONG, got %s', FrameTag::name($tag)));
        }
    }

    /**
     * Ask the server to stop one of this principal's running statements.
     *
     * Send this on a **second** connection: the one running the statement is
     * waiting for its reply and cannot carry anything else.
     *
     * @return int How many executions were cancelled; 0 for an id the server does not know.
     */
    public function cancel(string $requestId): int
    {
        [$tag, $body] = $this->exchange(FrameTag::CANCEL, ['request_id' => $requestId]);
        if ($tag === FrameTag::ERROR) {
            throw new ProtocolException(self::errorText($body), self::errorCode($body));
        }
        if ($tag !== FrameTag::CANCEL_OK) {
            $this->failStream(sprintf('expected CANCEL_OK, got %s', FrameTag::name($tag)));
        }

        return is_array($body) && is_int($body['cancelled'] ?? null) ? $body['cancelled'] : 0;
    }

    /** Say goodbye and close the socket. Safe to call more than once; never throws. */
    public function close(): void
    {
        if ($this->isClosed()) {
            return;
        }
        try {
            $this->transport->setReadTimeout(self::CLOSE_TIMEOUT_SECONDS);
            $this->exchange(FrameTag::CLOSE, null);
        } catch (\Throwable) {
            // Leaving politely is best effort; the socket closes either way.
        } finally {
            $this->transport->close();
            $this->transactionOpen = false;
        }
    }

    // ---- requests ------------------------------------------------------------

    /**
     * Send a raw operation, such as `['Cache' => 'Ping']`.
     *
     * @param mixed $op An externally tagged operation.
     * @param string|null $database The database; null uses the connection's.
     * @param string|null $correlationId An id the server echoes into its logs; needs CORRELATION_ID.
     * @return Response Only a response whose status is `ok`.
     * @throws ServerException For any other status.
     */
    public function request(mixed $op, ?string $database = null, ?string $correlationId = null): Response
    {
        $payload = [
            'request_id' => $this->nextRequestId(),
            'database' => $database ?? $this->database,
            'region_hint' => null,
            'op' => $op,
        ];
        if ($correlationId !== null) {
            $this->requireFeature(Features::CORRELATION_ID, 'CORRELATION_ID', 'a correlation id');
            $payload['correlation_id'] = $correlationId;
        }
        if ($this->requestTimeoutMs !== null) {
            $payload['options'] = [
                'cache' => ['mode' => 'disabled'],
                'output' => 'native',
                'consistency' => 'strong_primary',
                'timeout_ms' => $this->requestTimeoutMs,
                'llm' => null,
            ];
        }
        [$tag, $body] = $this->exchange(FrameTag::REQUEST, $payload);
        if ($tag === FrameTag::ERROR) {
            throw new ProtocolException(self::errorText($body), self::errorCode($body));
        }
        if ($tag !== FrameTag::RESPONSE) {
            $this->failStream(sprintf('expected RESPONSE, got %s', FrameTag::name($tag)));
        }
        $response = Response::fromWire($body);
        if (!$response->isOk()) {
            $error = ServerException::fromResponse($response, $this->transactionOpen);
            if ($response->isRedirect()) {
                $this->transactionOpen = false;
            }

            throw $error;
        }

        return $response;
    }

    // ---- SQL -----------------------------------------------------------------

    /**
     * Run a statement that is not a `SELECT`: DDL, `INSERT`, `UPDATE`, `DELETE`,
     * or a whole transaction script.
     *
     * Values in `$params` are bound by the server; this driver never writes them
     * into the statement text.
     *
     * @param list<mixed> $params Values for `?` placeholders.
     * @return Response Use {@see Response::rowsAffected()} for the change count.
     * @throws FeatureNotGrantedException When parameters are given and SERVER_PARAMS was not granted.
     */
    public function execute(string $sql, array $params = [], ?string $database = null): Response
    {
        return $this->request(['Sql' => ['Exec' => $this->sqlBody($sql, $params)]], $database);
    }

    /**
     * Run a `SELECT`. The server refuses a write sent this way.
     *
     * @param list<mixed> $params Values for `?` placeholders.
     */
    public function query(string $sql, array $params = [], ?string $database = null): Rows
    {
        return $this->request(['Sql' => ['Query' => $this->sqlBody($sql, $params)]], $database)->rows('query');
    }

    /**
     * Open a session transaction on this connection.
     *
     * Needs SESSION_TXN. Without it, send a whole `BEGIN; …; COMMIT` script
     * through {@see Client::execute()} instead.
     *
     * @return array<string, mixed> The server's outcome.
     */
    public function begin(?string $database = null): array
    {
        $this->requireFeature(
            Features::SESSION_TXN,
            'SESSION_TXN',
            'begin/commit/rollback as separate requests (send a whole `BEGIN; ...; COMMIT` script with execute() instead)'
        );

        return $this->transactionControl('BEGIN', $database);
    }

    /**
     * Commit the open transaction.
     *
     * @return array<string, mixed>
     */
    public function commit(?string $database = null): array
    {
        return $this->transactionControl('COMMIT', $database);
    }

    /**
     * Discard the open transaction.
     *
     * @return array<string, mixed>
     */
    public function rollback(?string $database = null): array
    {
        return $this->transactionControl('ROLLBACK', $database);
    }

    /**
     * Begin, call `$work` with this connection, and commit. If `$work` throws,
     * roll back and rethrow the original exception.
     *
     * @template T
     * @param callable(Client): T $work
     * @return T
     */
    public function transaction(callable $work, ?string $database = null): mixed
    {
        $this->begin($database);
        try {
            $result = $work($this);
        } catch (\Throwable $e) {
            if ($this->inTransaction()) {
                try {
                    $this->rollback($database);
                } catch (\Throwable) {
                    // The original failure is the one worth reporting.
                }
            }

            throw $e;
        }
        $this->commit($database);

        return $result;
    }

    // ---- cache ---------------------------------------------------------------
    //
    // Cache values are bytes: any PHP string (or Bytes) goes as it is, and values
    // come back as PHP strings holding the raw bytes.

    /** A liveness check routed through the cache module. */
    public function cachePing(?string $database = null): void
    {
        $this->request(['Cache' => 'Ping'], $database);
    }

    /** Store a value, expiring after `$ttlMs` milliseconds when given. */
    public function cacheSet(string $namespace, string $key, string|Bytes $value, ?int $ttlMs = null, ?string $database = null): void
    {
        $this->cache('Set', self::nk($namespace, $key) + [
            'value' => Wire::argument($value, 'value'),
            'ttl_ms' => $ttlMs,
        ], $database);
    }

    /** Read a value. Null is a miss, which is how a miss is told apart from an empty value. */
    public function cacheGet(string $namespace, string $key, ?string $database = null): ?string
    {
        return $this->cache('Get', self::nk($namespace, $key), $database)->cacheValue('Get');
    }

    /** Store a value only if the key is absent, reporting whether this call stored it. */
    public function cacheSetNx(string $namespace, string $key, string|Bytes $value, ?int $ttlMs = null, ?string $database = null): bool
    {
        $json = $this->cacheJson('SetNx', self::nk($namespace, $key) + [
            'value' => Wire::argument($value, 'value'),
            'ttl_ms' => $ttlMs,
        ], $database);

        return ($json['set'] ?? false) === true;
    }

    /** Delete a key, reporting whether it existed. */
    public function cacheDelete(string $namespace, string $key, ?string $database = null): bool
    {
        return ($this->cacheJson('Delete', self::nk($namespace, $key), $database)['deleted'] ?? false) === true;
    }

    /** Whether a key is present. */
    public function cacheExists(string $namespace, string $key, ?string $database = null): bool
    {
        return ($this->cacheJson('Exists', self::nk($namespace, $key), $database)['exists'] ?? false) === true;
    }

    /** Milliseconds left before a key expires; null when it is missing or never expires. */
    public function cacheTtl(string $namespace, string $key, ?string $database = null): ?int
    {
        $ttl = $this->cacheJson('Ttl', self::nk($namespace, $key), $database)['ttl_ms'] ?? null;

        return is_int($ttl) ? $ttl : null;
    }

    /** Give a key a new expiry; false when there is no such key. */
    public function cacheExpire(string $namespace, string $key, int $ttlMs, ?string $database = null): bool
    {
        return ($this->cacheJson('Expire', self::nk($namespace, $key) + ['ttl_ms' => $ttlMs], $database)['updated'] ?? false) === true;
    }

    /** Remove a key's expiry; false when it had none. */
    public function cachePersist(string $namespace, string $key, ?string $database = null): bool
    {
        return ($this->cacheJson('Persist', self::nk($namespace, $key), $database)['persisted'] ?? false) === true;
    }

    /** Add to a counter, returning its new value. */
    public function cacheIncr(string $namespace, string $key, int $by = 1, ?string $database = null): int
    {
        return self::int($this->cacheJson('Incr', self::nk($namespace, $key) + ['by' => $by], $database), 'value');
    }

    /** Delete every key in a namespace, returning how many went. */
    public function cacheClearNamespace(string $namespace, ?string $database = null): int
    {
        return self::int($this->cacheJson('ClearNamespace', ['namespace' => $namespace], $database), 'cleared');
    }

    /**
     * The live keys in a namespace. `$pattern` is a glob where `*` matches any run of characters.
     *
     * @return list<array<string, mixed>> One entry per key: `key`, and the TTL and size the server reports.
     */
    public function cacheKeys(string $namespace, ?string $pattern = null, ?int $limit = null, ?string $database = null): array
    {
        return self::list($this->cacheJson('Keys', ['namespace' => $namespace, 'pattern' => $pattern, 'limit' => $limit], $database), 'keys');
    }

    /**
     * Push values onto the head of a list, returning its new length.
     *
     * @param list<string|Bytes> $values
     */
    public function cacheLPush(string $namespace, string $key, array $values, ?string $database = null): int
    {
        return self::int($this->cacheJson('LPush', self::nk($namespace, $key) + ['values' => self::byteList($values, 'values')], $database), 'length');
    }

    /**
     * Push values onto the tail of a list, returning its new length.
     *
     * @param list<string|Bytes> $values
     */
    public function cacheRPush(string $namespace, string $key, array $values, ?string $database = null): int
    {
        return self::int($this->cacheJson('RPush', self::nk($namespace, $key) + ['values' => self::byteList($values, 'values')], $database), 'length');
    }

    /** Take a value from the head of a list; null when it is empty. */
    public function cacheLPop(string $namespace, string $key, ?string $database = null): ?string
    {
        return $this->cache('LPop', self::nk($namespace, $key), $database)->cacheValue('LPop');
    }

    /** Take a value from the tail of a list; null when it is empty. */
    public function cacheRPop(string $namespace, string $key, ?string $database = null): ?string
    {
        return $this->cache('RPop', self::nk($namespace, $key), $database)->cacheValue('RPop');
    }

    /**
     * An inclusive slice of a list. Negative indices count from the end.
     *
     * @return list<string>
     */
    public function cacheLRange(string $namespace, string $key, int $start, int $stop, ?string $database = null): array
    {
        $json = $this->cacheJson('LRange', self::nk($namespace, $key) + ['start' => $start, 'stop' => $stop], $database);

        return self::binaries($json['values'] ?? [], 'LRange');
    }

    /** How many values a list holds. */
    public function cacheLLen(string $namespace, string $key, ?string $database = null): int
    {
        return self::int($this->cacheJson('LLen', self::nk($namespace, $key), $database), 'length');
    }

    /** One value of a list by position; null when out of range. */
    public function cacheLIndex(string $namespace, string $key, int $index, ?string $database = null): ?string
    {
        return $this->cache('LIndex', self::nk($namespace, $key) + ['index' => $index], $database)->cacheValue('LIndex');
    }

    /**
     * Add members to a set, returning how many were new.
     *
     * @param list<string|Bytes> $members
     */
    public function cacheSAdd(string $namespace, string $key, array $members, ?string $database = null): int
    {
        return self::int($this->cacheJson('SAdd', self::nk($namespace, $key) + ['members' => self::byteList($members, 'members')], $database), 'added');
    }

    /**
     * Remove members from a set, returning how many were present.
     *
     * @param list<string|Bytes> $members
     */
    public function cacheSRem(string $namespace, string $key, array $members, ?string $database = null): int
    {
        return self::int($this->cacheJson('SRem', self::nk($namespace, $key) + ['members' => self::byteList($members, 'members')], $database), 'removed');
    }

    /** Whether a set holds a member. */
    public function cacheSIsMember(string $namespace, string $key, string|Bytes $member, ?string $database = null): bool
    {
        $json = $this->cacheJson('SIsMember', self::nk($namespace, $key) + ['member' => Wire::argument($member, 'member')], $database);

        return ($json['is_member'] ?? false) === true;
    }

    /** How many members a set holds. */
    public function cacheSCard(string $namespace, string $key, ?string $database = null): int
    {
        return self::int($this->cacheJson('SCard', self::nk($namespace, $key), $database), 'cardinality');
    }

    /**
     * A set's members, in ascending byte order.
     *
     * @return list<string>
     */
    public function cacheSMembers(string $namespace, string $key, ?string $database = null): array
    {
        return self::binaries($this->cacheJson('SMembers', self::nk($namespace, $key), $database)['members'] ?? [], 'SMembers');
    }

    /**
     * Set hash fields, returning how many were created rather than replaced.
     *
     * @param array<array-key, string|Bytes>|list<array{0: string|Bytes, 1: string|Bytes}> $entries
     *        `field => value`, or a list of `[field, value]` pairs for fields PHP would turn into integer keys.
     */
    public function cacheHSet(string $namespace, string $key, array $entries, ?string $database = null): int
    {
        return self::int($this->cacheJson('HSet', self::nk($namespace, $key) + ['entries' => self::pairs($entries, 'entries')], $database), 'created');
    }

    /** One hash field; null when absent. */
    public function cacheHGet(string $namespace, string $key, string|Bytes $field, ?string $database = null): ?string
    {
        return $this->cache('HGet', self::nk($namespace, $key) + ['field' => Wire::argument($field, 'field')], $database)->cacheValue('HGet');
    }

    /**
     * Delete hash fields, returning how many were present.
     *
     * @param list<string|Bytes> $fields
     */
    public function cacheHDel(string $namespace, string $key, array $fields, ?string $database = null): int
    {
        return self::int($this->cacheJson('HDel', self::nk($namespace, $key) + ['fields' => self::byteList($fields, 'fields')], $database), 'deleted');
    }

    /**
     * Every field of a hash, as `[field, value]` byte pairs in ascending field order.
     *
     * Pairs rather than a map, because PHP would turn a numeric field name into an integer key.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function cacheHGetAll(string $namespace, string $key, ?string $database = null): array
    {
        return self::bytePairs($this->cacheJson('HGetAll', self::nk($namespace, $key), $database)['entries'] ?? [], 'HGetAll');
    }

    /** Whether a hash holds a field. */
    public function cacheHExists(string $namespace, string $key, string|Bytes $field, ?string $database = null): bool
    {
        $json = $this->cacheJson('HExists', self::nk($namespace, $key) + ['field' => Wire::argument($field, 'field')], $database);

        return ($json['exists'] ?? false) === true;
    }

    /** How many fields a hash holds. */
    public function cacheHLen(string $namespace, string $key, ?string $database = null): int
    {
        return self::int($this->cacheJson('HLen', self::nk($namespace, $key), $database), 'length');
    }

    /**
     * Append a stream entry, returning its `<ms>-<seq>` id.
     *
     * @param array<array-key, string|Bytes>|list<array{0: string|Bytes, 1: string|Bytes}> $fields
     * @param string|null $id An explicit id; null (or `*`) lets the server choose.
     */
    public function cacheXAdd(string $namespace, string $key, array $fields, ?string $id = null, ?string $database = null): string
    {
        $json = $this->cacheJson('XAdd', self::nk($namespace, $key) + ['id' => $id, 'fields' => self::pairs($fields, 'fields')], $database);
        if (!is_string($json['id'] ?? null)) {
            throw new ProtocolException('XAdd answered without an id', ErrorCode::PROTOCOL);
        }

        return $json['id'];
    }

    /** How many entries a stream holds. */
    public function cacheXLen(string $namespace, string $key, ?string $database = null): int
    {
        return self::int($this->cacheJson('XLen', self::nk($namespace, $key), $database), 'length');
    }

    /**
     * Stream entries between two ids, oldest first. `-` and `+` are the ends.
     *
     * @return list<StreamEntry>
     */
    public function cacheXRange(string $namespace, string $key, string $start = '-', string $end = '+', ?int $count = null, ?string $database = null): array
    {
        return self::streamEntries($this->cacheJson('XRange', self::nk($namespace, $key) + ['start' => $start, 'end' => $end, 'count' => $count], $database));
    }

    /**
     * Entries strictly newer than `$after`. This never blocks.
     *
     * @return list<StreamEntry>
     */
    public function cacheXRead(string $namespace, string $key, string $after = '0-0', ?int $count = null, ?string $database = null): array
    {
        return self::streamEntries($this->cacheJson('XRead', self::nk($namespace, $key) + ['after' => $after, 'count' => $count], $database));
    }

    /**
     * Delete stream entries by id, returning how many went.
     *
     * @param list<string> $ids
     */
    public function cacheXDel(string $namespace, string $key, array $ids, ?string $database = null): int
    {
        return self::int($this->cacheJson('XDel', self::nk($namespace, $key) + ['ids' => array_values($ids)], $database), 'deleted');
    }

    /** Trim a stream to its newest `$maxLen` entries, returning how many were evicted. */
    public function cacheXTrim(string $namespace, string $key, int $maxLen, ?string $database = null): int
    {
        return self::int($this->cacheJson('XTrim', self::nk($namespace, $key) + ['max_len' => $maxLen], $database), 'trimmed');
    }

    // ---- documents -----------------------------------------------------------

    /** Create a collection. */
    public function docCreateCollection(string $collection, ?string $database = null): void
    {
        $this->document('CreateCollection', ['collection' => $collection], $database);
    }

    /** Drop a collection and every document in it. */
    public function docDropCollection(string $collection, ?string $database = null): void
    {
        $this->document('DropCollection', ['collection' => $collection], $database);
    }

    /**
     * Every collection in the database.
     *
     * @return list<string>
     */
    public function docListCollections(?string $database = null): array
    {
        return self::list($this->request(['Document' => 'ListCollections'], $database)->json('ListCollections'), 'collections');
    }

    /**
     * Insert a document, returning the id it was stored under.
     *
     * Inserting over an existing id is an error, not an overwrite; see
     * {@see Client::docUpdateOne()} with `upsert: true`.
     *
     * @param array<string, mixed>|object $document
     * @param string|null $id An explicit id; null lets the server choose.
     */
    public function docInsert(string $collection, array|object $document, ?string $id = null, ?string $database = null): string
    {
        $json = $this->document('Insert', [
            'collection' => $collection,
            'id' => $id,
            'document' => Json::object($document, 'document'),
        ], $database)->json('Insert');
        if (!is_array($json) || !is_string($json['id'] ?? null)) {
            throw new ProtocolException('Insert answered without a document id', ErrorCode::PROTOCOL);
        }

        return $json['id'];
    }

    /**
     * One document by id; null when there is no such document.
     *
     * @return array<string, mixed>|null
     */
    public function docGet(string $collection, string $id, ?string $database = null): ?array
    {
        $documents = $this->document('Get', ['collection' => $collection, 'id' => $id], $database)->documents('Get');

        return $documents[0] ?? null;
    }

    /**
     * Every document a filter matches, at most `$limit` of them.
     *
     * @return list<array<string, mixed>>
     */
    public function docFind(string $collection, ?Filter $filter = null, ?int $limit = null, ?string $database = null): array
    {
        return $this->document('Find', [
            'collection' => $collection,
            'filter' => $filter ?? Filter::all(),
            'limit' => $limit,
        ], $database)->documents('Find');
    }

    /**
     * Set fields on an existing document, by dot path. This is not an upsert.
     *
     * @param array<string, mixed>|object $set
     */
    public function docUpdate(string $collection, string $id, array|object $set, ?string $database = null): void
    {
        $this->document('Update', [
            'collection' => $collection,
            'id' => $id,
            'set' => Json::object($set, 'set'),
        ], $database);
    }

    /**
     * Set and increment fields on one document, optionally inserting it when absent.
     *
     * @param array<string, mixed>|null $set
     * @param array<string, int|float>|null $inc
     * @return array<string, mixed> `updated`, `inserted` and `id`.
     */
    public function docUpdateOne(string $collection, string $id, ?array $set = null, ?array $inc = null, bool $upsert = false, ?string $database = null): array
    {
        return self::map($this->document('UpdateOne', [
            'collection' => $collection,
            'id' => $id,
            'update' => self::updateBody($set, $inc),
            'upsert' => $upsert,
        ], $database)->json('UpdateOne'));
    }

    /**
     * Set and increment fields on every document a filter matches.
     *
     * @param array<string, mixed>|null $set
     * @param array<string, int|float>|null $inc
     * @return array<string, mixed> `matched` and `modified`.
     */
    public function docUpdateMany(string $collection, Filter $filter, ?array $set = null, ?array $inc = null, ?string $database = null): array
    {
        return self::map($this->document('UpdateMany', [
            'collection' => $collection,
            'filter' => $filter,
            'update' => self::updateBody($set, $inc),
        ], $database)->json('UpdateMany'));
    }

    /** Delete one document by id. */
    public function docDelete(string $collection, string $id, ?string $database = null): void
    {
        $this->document('Delete', ['collection' => $collection, 'id' => $id], $database);
    }

    /** Create an index on a field. */
    public function docCreateIndex(string $collection, string $indexName, string $field, bool $unique = false, ?string $database = null): void
    {
        $this->document('CreateIndex', [
            'collection' => $collection,
            'index_name' => $indexName,
            'field' => $field,
            'unique' => $unique,
        ], $database);
    }

    /** Drop an index by name. */
    public function docDropIndex(string $collection, string $indexName, ?string $database = null): void
    {
        $this->document('DropIndex', ['collection' => $collection, 'index_name' => $indexName], $database);
    }

    /**
     * The indexes on a collection.
     *
     * @return list<array<string, mixed>> `index_name`, `field` and `unique`.
     */
    public function docListIndexes(string $collection, ?string $database = null): array
    {
        return self::list($this->document('ListIndexes', ['collection' => $collection], $database)->json('ListIndexes'), 'indexes');
    }

    /**
     * Statistics about a collection.
     *
     * @return array<string, mixed>
     */
    public function docAnalyze(string $collection, ?string $database = null): array
    {
        return self::map($this->document('Analyze', ['collection' => $collection], $database)->json('Analyze'));
    }

    /**
     * Run an aggregation pipeline built from {@see Stage}.
     *
     * @param list<Stage> $pipeline
     * @return list<array<string, mixed>>
     */
    public function docAggregate(string $collection, array $pipeline, ?string $database = null): array
    {
        foreach ($pipeline as $stage) {
            if (!$stage instanceof Stage) {
                throw new InvalidValueException(sprintf('a pipeline holds Stage values, got %s', get_debug_type($stage)));
            }
        }

        return $this->document('Aggregate', [
            'collection' => $collection,
            'pipeline' => array_values($pipeline),
        ], $database)->documents('Aggregate');
    }

    // ---- vectors -------------------------------------------------------------

    /** Create a collection. Its dimension and metric are fixed for its lifetime. */
    public function vectorCreateCollection(
        string $collection,
        int $dimension,
        VectorMetric|string $metric = VectorMetric::Cosine,
        VectorQuantization|string $quantization = VectorQuantization::None,
        ?string $database = null
    ): void {
        $this->vector('CreateCollection', [
            'collection' => $collection,
            'dimension' => $dimension,
            'metric' => Enums::value($metric, VectorMetric::class, 'metric'),
            'quantization' => Enums::value($quantization, VectorQuantization::class, 'quantization'),
        ], $database);
    }

    /** Drop a collection and every vector in it. */
    public function vectorDropCollection(string $collection, ?string $database = null): void
    {
        $this->vector('DropCollection', ['collection' => $collection], $database);
    }

    /**
     * Store a vector under an id, replacing whatever was there.
     *
     * Its length must equal the collection's dimension; a mismatch is refused,
     * not padded or truncated.
     *
     * @param list<int|float> $values
     * @param array<string, mixed>|null $metadata
     * @return string The id.
     */
    public function vectorUpsert(string $collection, string $id, array $values, ?array $metadata = null, ?string $database = null): string
    {
        $json = $this->vector('Upsert', [
            'collection' => $collection,
            'id' => $id,
            'vector' => self::numbers($values),
            'metadata' => Json::objectOrNull($metadata, 'metadata'),
        ], $database)->json('Upsert');

        return is_array($json) && is_string($json['id'] ?? null) ? $json['id'] : $id;
    }

    /**
     * One stored vector with its metadata; null when there is no such id.
     *
     * @return array<string, mixed>|null `id`, `vector` and `metadata`.
     */
    public function vectorGet(string $collection, string $id, ?string $database = null): ?array
    {
        $json = $this->vector('Get', ['collection' => $collection, 'id' => $id], $database)->json('Get');

        return is_array($json) ? $json : null;
    }

    /** Delete one vector. An absent id is not an error; a missing collection is. */
    public function vectorDelete(string $collection, string $id, ?string $database = null): void
    {
        $this->vector('Delete', ['collection' => $collection, 'id' => $id], $database);
    }

    /**
     * The `$topK` nearest vectors, best first. Higher scores are closer for every metric.
     *
     * @param list<int|float> $values
     * @param array<string, mixed>|null $filter Metadata fields that must match exactly.
     * @return list<array<string, mixed>> `id`, `score` and `metadata`.
     */
    public function vectorSearch(string $collection, array $values, int $topK, ?array $filter = null, ?string $database = null): array
    {
        return self::list($this->vector('Search', [
            'collection' => $collection,
            'vector' => self::numbers($values),
            'top_k' => $topK,
            'filter' => $filter === null || $filter === [] ? null : Json::object($filter, 'filter'),
        ], $database)->json('Search'), 'results');
    }

    /**
     * Every vector collection in the database.
     *
     * @return list<string>
     */
    public function vectorListCollections(?string $database = null): array
    {
        return self::list($this->request(['Vector' => 'ListCollections'], $database)->json('ListCollections'), 'collections');
    }

    /**
     * A collection's dimension, metric, quantization and size.
     *
     * @return array<string, mixed>
     */
    public function vectorDescribeCollection(string $collection, ?string $database = null): array
    {
        return self::map($this->vector('DescribeCollection', ['collection' => $collection], $database)->json('DescribeCollection'));
    }

    /**
     * A page of the vectors in a collection.
     *
     * @return array<string, mixed> `vectors`, `count`, `total` and `truncated`.
     */
    public function vectorListVectors(string $collection, ?int $limit = null, ?int $offset = null, ?string $database = null): array
    {
        return self::map($this->vector('ListVectors', [
            'collection' => $collection,
            'limit' => $limit,
            'offset' => $offset,
        ], $database)->json('ListVectors'));
    }

    // ---- graphs --------------------------------------------------------------

    /** Create a graph. */
    public function graphCreate(string $graph, ?string $database = null): void
    {
        $this->graph('CreateGraph', ['graph' => $graph], $database);
    }

    /** Drop a graph with its nodes and edges. */
    public function graphDrop(string $graph, ?string $database = null): void
    {
        $this->graph('DropGraph', ['graph' => $graph], $database);
    }

    /**
     * Every graph in the database.
     *
     * @return list<string>
     */
    public function graphList(?string $database = null): array
    {
        return self::list($this->request(['Graph' => 'ListGraphs'], $database)->json('ListGraphs'), 'graphs');
    }

    /**
     * Add a node, returning its id.
     *
     * @param list<string> $labels
     * @param array<string, mixed> $properties
     */
    public function graphAddNode(string $graph, string $id, array $labels = [], array $properties = [], ?string $database = null): string
    {
        $json = $this->graph('AddNode', [
            'graph' => $graph,
            'id' => $id,
            'labels' => array_values($labels),
            'properties' => Json::object($properties, 'properties'),
        ], $database)->json('AddNode');

        return is_array($json) && is_string($json['id'] ?? null) ? $json['id'] : $id;
    }

    /**
     * One node by id; null when there is no such node.
     *
     * @return array<string, mixed>|null `id`, `labels` and `properties`.
     */
    public function graphGetNode(string $graph, string $id, ?string $database = null): ?array
    {
        $json = $this->graph('GetNode', ['graph' => $graph, 'id' => $id], $database)->json('GetNode');

        return is_array($json) ? $json : null;
    }

    /** Delete a node and the edges that touch it. */
    public function graphDeleteNode(string $graph, string $id, ?string $database = null): void
    {
        $this->graph('DeleteNode', ['graph' => $graph, 'id' => $id], $database);
    }

    /**
     * Add an edge, returning its id.
     *
     * @param array<string, mixed> $properties
     */
    public function graphAddEdge(string $graph, string $id, string $from, string $to, string $label, array $properties = [], ?string $database = null): string
    {
        $json = $this->graph('AddEdge', [
            'graph' => $graph,
            'id' => $id,
            'from' => $from,
            'to' => $to,
            'label' => $label,
            'properties' => Json::object($properties, 'properties'),
        ], $database)->json('AddEdge');

        return is_array($json) && is_string($json['id'] ?? null) ? $json['id'] : $id;
    }

    /**
     * One edge by id; null when there is no such edge.
     *
     * @return array<string, mixed>|null `id`, `from`, `to`, `label` and `properties`.
     */
    public function graphGetEdge(string $graph, string $id, ?string $database = null): ?array
    {
        $json = $this->graph('GetEdge', ['graph' => $graph, 'id' => $id], $database)->json('GetEdge');

        return is_array($json) ? $json : null;
    }

    /** Delete one edge. */
    public function graphDeleteEdge(string $graph, string $id, ?string $database = null): void
    {
        $this->graph('DeleteEdge', ['graph' => $graph, 'id' => $id], $database);
    }

    /**
     * The nodes one hop away.
     *
     * @return list<array<string, mixed>> `node_id`, `edge_id`, `label` and `direction`.
     */
    public function graphNeighbors(
        string $graph,
        string $nodeId,
        GraphDirection|string $direction = GraphDirection::Outgoing,
        ?string $label = null,
        ?int $limit = null,
        ?string $database = null
    ): array {
        return self::list($this->graph('Neighbors', [
            'graph' => $graph,
            'node_id' => $nodeId,
            'direction' => Enums::value($direction, GraphDirection::class, 'direction'),
            'label' => $label,
            'limit' => $limit,
        ], $database)->json('Neighbors'), 'neighbors');
    }

    /** How many edges touch a node. */
    public function graphDegree(string $graph, string $nodeId, GraphDirection|string $direction = GraphDirection::Outgoing, ?string $database = null): int
    {
        return self::int(self::map($this->graph('Degree', [
            'graph' => $graph,
            'node_id' => $nodeId,
            'direction' => Enums::value($direction, GraphDirection::class, 'direction'),
        ], $database)->json('Degree')), 'degree');
    }

    /**
     * A bounded breadth-first walk from a node.
     *
     * @return array<string, mixed> `nodes` (each with its `depth`), `count` and `truncated`.
     */
    public function graphTraverse(
        string $graph,
        string $start,
        GraphDirection|string $direction = GraphDirection::Outgoing,
        ?string $label = null,
        ?int $maxDepth = null,
        ?int $limit = null,
        ?string $database = null
    ): array {
        return self::map($this->graph('Traverse', [
            'graph' => $graph,
            'start' => $start,
            'direction' => Enums::value($direction, GraphDirection::class, 'direction'),
            'label' => $label,
            'max_depth' => $maxDepth,
            'limit' => $limit,
        ], $database)->json('Traverse'));
    }

    /**
     * The path with the fewest hops. "No path" is `found: false`, not an error.
     *
     * @return array<string, mixed> `found`, `hops`, `node_path` and `edge_path`.
     */
    public function graphShortestPath(
        string $graph,
        string $from,
        string $to,
        GraphDirection|string $direction = GraphDirection::Outgoing,
        ?string $label = null,
        ?int $maxDepth = null,
        ?string $database = null
    ): array {
        return self::map($this->graph('ShortestPath', [
            'graph' => $graph,
            'from' => $from,
            'to' => $to,
            'direction' => Enums::value($direction, GraphDirection::class, 'direction'),
            'label' => $label,
            'max_depth' => $maxDepth,
        ], $database)->json('ShortestPath'));
    }

    /**
     * The path with the least summed edge weight.
     *
     * @return array<string, mixed> `found`, `total_cost`, `node_path` and `edge_path`.
     */
    public function graphWeightedShortestPath(
        string $graph,
        string $from,
        string $to,
        GraphDirection|string $direction = GraphDirection::Outgoing,
        ?string $label = null,
        ?string $weightProperty = null,
        ?string $database = null
    ): array {
        return self::map($this->graph('WeightedShortestPath', [
            'graph' => $graph,
            'from' => $from,
            'to' => $to,
            'direction' => Enums::value($direction, GraphDirection::class, 'direction'),
            'label' => $label,
            'weight_property' => $weightProperty,
        ], $database)->json('WeightedShortestPath'));
    }

    /**
     * A page of a graph's nodes.
     *
     * @return array<string, mixed> `nodes`, `count`, `total` and `truncated`.
     */
    public function graphListNodes(string $graph, ?int $limit = null, ?int $offset = null, ?string $database = null): array
    {
        return self::map($this->graph('ListNodes', ['graph' => $graph, 'limit' => $limit, 'offset' => $offset], $database)->json('ListNodes'));
    }

    /**
     * A page of a graph's edges.
     *
     * @return array<string, mixed> `edges`, `count`, `total` and `truncated`.
     */
    public function graphListEdges(string $graph, ?int $limit = null, ?int $offset = null, ?string $database = null): array
    {
        return self::map($this->graph('ListEdges', ['graph' => $graph, 'limit' => $limit, 'offset' => $offset], $database)->json('ListEdges'));
    }

    /**
     * Run a read-only Cypher query over a graph.
     *
     * @return array<string, mixed> `columns`, `rows`, `count` and `truncated`.
     */
    public function graphQuery(string $graph, string $cypher, ?string $database = null): array
    {
        return self::map($this->graph('Query', ['graph' => $graph, 'cypher' => $cypher], $database)->json('Query'));
    }

    // ---- LLM -----------------------------------------------------------------

    /**
     * Export the schema catalogue.
     *
     * @return mixed Text for `toon` and `markdown`, a decoded JSON value otherwise.
     */
    public function llmSchema(
        OutputFormat|string $format = OutputFormat::Toon,
        ?int $maxRows = null,
        bool $redactSensitive = true,
        bool $includeSchema = false,
        ?string $database = null
    ): mixed {
        return $this->request(['Llm' => ['Schema' => [
            'format' => Enums::value($format, OutputFormat::class, 'format'),
            'options' => self::llmOptions($maxRows, $redactSensitive, $includeSchema),
        ]]], $database)->rendered('Schema');
    }

    /**
     * Assemble a context bundle from read-only sources.
     *
     * @param list<LlmSource> $sources
     * @return mixed Text for `toon` and `markdown`, a decoded JSON value otherwise.
     */
    public function llmContext(
        array $sources,
        OutputFormat|string $format = OutputFormat::Toon,
        ?int $maxRows = null,
        bool $redactSensitive = true,
        bool $includeSchema = false,
        ?string $database = null
    ): mixed {
        if ($sources === []) {
            throw new InvalidValueException('a context bundle needs at least one source');
        }
        foreach ($sources as $source) {
            if (!$source instanceof LlmSource) {
                throw new InvalidValueException(sprintf('context sources are LlmSource values, got %s', get_debug_type($source)));
            }
        }

        return $this->request(['Llm' => ['Context' => [
            'sources' => array_values($sources),
            'format' => Enums::value($format, OutputFormat::class, 'format'),
            'options' => self::llmOptions($maxRows, $redactSensitive, $includeSchema),
        ]]], $database)->rendered('Context');
    }

    // ---- admin ---------------------------------------------------------------

    /** A round trip through authentication, routing and dispatch, not just the socket. */
    public function adminPing(?string $database = null): void
    {
        $this->request(['Admin' => 'Ping'], $database);
    }

    /**
     * What the server says about itself.
     *
     * @return array<string, mixed>
     */
    public function adminStatus(?string $database = null): array
    {
        $response = $this->request(['Admin' => 'Status'], $database);
        $message = $response->message();
        if ($message !== null) {
            return ['message' => $message];
        }

        return self::map($response->json('Status'));
    }

    // ---- internals -----------------------------------------------------------

    private function handshake(string $clientName, int $features): void
    {
        [$tag, $body] = $this->exchange(FrameTag::HELLO, [
            'protocol' => 'tricore',
            'version' => ['major' => 1, 'minor' => 0],
            'client' => $clientName,
            'features' => $features,
        ]);
        if ($tag === FrameTag::ERROR) {
            $this->failStream(self::errorText($body), self::errorCode($body));
        }
        if ($tag !== FrameTag::HELLO_OK) {
            $this->failStream(sprintf('expected HELLO_OK, got %s', FrameTag::name($tag)), ErrorCode::FRAME_TAG);
        }
        if (!is_array($body) || ($body['ok'] ?? null) !== true) {
            $message = is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : 'the handshake was refused';
            $this->failStream($message, self::errorCode($body));
        }
        $this->grantedFeatures = is_int($body['features'] ?? null) ? $body['features'] : 0;
    }

    private function authenticate(string $user, string $secret): void
    {
        [$tag, $body] = $this->exchange(FrameTag::AUTH, [
            'username' => $user,
            'secret' => Wire::toByteList($secret),
        ]);
        if ($tag === FrameTag::ERROR) {
            $this->transport->close();

            throw new AuthenticationException(self::errorText($body), self::errorCode($body));
        }
        if ($tag !== FrameTag::AUTH_OK) {
            $this->failStream(sprintf('expected AUTH_OK, got %s', FrameTag::name($tag)), ErrorCode::FRAME_TAG);
        }
        // A refused login arrives as AUTH_OK carrying `ok: false`: the tag names
        // the answer's shape, the body is the verdict.
        if (!is_array($body) || ($body['ok'] ?? null) !== true) {
            $this->transport->close();

            throw new AuthenticationException(
                is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : 'authentication was refused',
                self::errorCode($body)
            );
        }
        $this->sessionId = is_string($body['session_id'] ?? null) ? $body['session_id'] : null;
    }

    /**
     * One frame out, one frame back. The transport closes itself on anything
     * that leaves the stream position unknown.
     *
     * @return array{0: int, 1: mixed}
     */
    private function exchange(int $tag, mixed $payload): array
    {
        try {
            $this->transport->send($tag, $payload);

            return $this->transport->receive();
        } catch (TriCoreException $e) {
            if ($this->isClosed()) {
                $this->transactionOpen = false;
            }

            throw $e;
        }
    }

    private function failStream(string $message, ?string $code = ErrorCode::PROTOCOL): never
    {
        $this->transport->close();
        $this->transactionOpen = false;

        throw new ProtocolException($message, $code);
    }

    private function nextRequestId(): string
    {
        $this->requestCounter++;

        return $this->lastRequestId = $this->requestPrefix . '-' . $this->requestCounter;
    }

    private function requireFeature(int $feature, string $name, string $what): void
    {
        if (!$this->hasFeature($feature)) {
            throw new FeatureNotGrantedException(
                sprintf('the server did not grant %s in the handshake, so this connection cannot use %s', $name, $what),
                ErrorCode::FEATURE_NOT_GRANTED
            );
        }
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>
     */
    private function sqlBody(string $sql, array $params): array
    {
        $body = ['sql' => $sql];
        if ($params === []) {
            return $body;
        }
        $this->requireFeature(
            Features::SERVER_PARAMS,
            'SERVER_PARAMS',
            'server-side `?` parameters; this driver will not write values into the SQL text instead, '
            . 'because escaping and binding are not the same guarantee'
        );
        $body['params'] = Params::encode($params);

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionControl(string $keyword, ?string $database): array
    {
        try {
            $response = $this->request(['Sql' => ['Exec' => ['sql' => $keyword]]], $database);
        } catch (TriCoreException $e) {
            if ($keyword !== 'BEGIN') {
                $this->transactionOpen = false;
            }

            throw $e;
        }
        $this->transactionOpen = $keyword === 'BEGIN';

        return self::map($response->json($keyword));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function cache(string $variant, array $body, ?string $database): Response
    {
        return $this->request(['Cache' => [$variant => $body]], $database);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function cacheJson(string $variant, array $body, ?string $database): array
    {
        return self::map($this->cache($variant, $body, $database)->json($variant));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function document(string $variant, array $body, ?string $database): Response
    {
        return $this->request(['Document' => [$variant => $body]], $database);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function vector(string $variant, array $body, ?string $database): Response
    {
        return $this->request(['Vector' => [$variant => $body]], $database);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function graph(string $variant, array $body, ?string $database): Response
    {
        return $this->request(['Graph' => [$variant => $body]], $database);
    }

    /**
     * @return array{namespace: string, key: string}
     */
    private static function nk(string $namespace, string $key): array
    {
        return ['namespace' => $namespace, 'key' => $key];
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $json): array
    {
        return is_array($json) ? $json : [];
    }

    /**
     * @param array<string, mixed> $json
     * @return list<mixed>
     */
    private static function list(mixed $json, string $field): array
    {
        $value = is_array($json) ? ($json[$field] ?? []) : [];

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param array<string, mixed> $json
     */
    private static function int(array $json, string $field): int
    {
        if (!is_int($json[$field] ?? null)) {
            throw new ProtocolException(sprintf('the server answered without an integer `%s`', $field), ErrorCode::PROTOCOL);
        }

        return $json[$field];
    }

    /**
     * @param array<mixed> $values
     * @return list<list<int>>
     */
    private static function byteList(array $values, string $name): array
    {
        if ($values === []) {
            throw new InvalidValueException(sprintf('%s must not be empty', $name));
        }
        $out = [];
        foreach (array_values($values) as $value) {
            $out[] = Wire::argument($value, $name . ' element');
        }

        return $out;
    }

    /**
     * Accept `field => value`, or a list of `[field, value]` pairs.
     *
     * @param array<mixed> $entries
     * @return list<array{0: list<int>, 1: list<int>}>
     */
    private static function pairs(array $entries, string $name): array
    {
        if ($entries === []) {
            throw new InvalidValueException(sprintf('%s must not be empty', $name));
        }
        $out = [];
        $isPairList = array_is_list($entries);
        if ($isPairList && !is_array($entries[0])) {
            // A plain list would otherwise become the fields "0", "1", ...
            throw new InvalidValueException(sprintf('%s must be field => value, or a list of [field, value] pairs', $name));
        }
        foreach ($entries as $field => $value) {
            if ($isPairList) {
                if (!is_array($value) || count($value) !== 2 || !array_is_list($value)) {
                    throw new InvalidValueException(sprintf('each %s entry must be a [field, value] pair', $name));
                }
                [$field, $value] = $value;
            }
            // PHP turns a numeric-string key into an int; the field is still its text.
            $out[] = [
                Wire::argument(is_int($field) ? (string) $field : $field, $name . ' field'),
                Wire::argument($value, $name . ' value'),
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function binaries(mixed $values, string $what): array
    {
        if (!is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $value) {
            $out[] = Wire::fromByteList($value, $what);
        }

        return $out;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function bytePairs(mixed $pairs, string $what): array
    {
        if (!is_array($pairs)) {
            return [];
        }
        $out = [];
        foreach ($pairs as $pair) {
            if (!is_array($pair) || count($pair) !== 2) {
                throw new ProtocolException(sprintf('%s answered with a malformed field pair', $what), ErrorCode::PROTOCOL);
            }
            $out[] = [Wire::fromByteList($pair[0], $what), Wire::fromByteList($pair[1], $what)];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $json
     * @return list<StreamEntry>
     */
    private static function streamEntries(array $json): array
    {
        $entries = $json['entries'] ?? [];
        if (!is_array($entries)) {
            return [];
        }
        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null)) {
                throw new ProtocolException('a stream entry arrived without an id', ErrorCode::PROTOCOL);
            }
            $out[] = new StreamEntry($entry['id'], self::bytePairs($entry['fields'] ?? [], 'stream entry'));
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $set
     * @param array<string, int|float>|null $inc
     */
    private static function updateBody(?array $set, ?array $inc): object
    {
        $body = new \stdClass();
        if ($set !== null && $set !== []) {
            $body->set = Json::object($set, 'set');
        }
        if ($inc !== null && $inc !== []) {
            $body->inc = Json::object($inc, 'inc');
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private static function llmOptions(?int $maxRows, bool $redactSensitive, bool $includeSchema): array
    {
        return [
            'max_rows' => $maxRows,
            'redact_sensitive' => $redactSensitive,
            'include_schema' => $includeSchema,
        ];
    }

    /**
     * @param array<mixed> $values
     * @return list<float>
     */
    private static function numbers(array $values): array
    {
        $out = [];
        foreach (array_values($values) as $i => $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new InvalidValueException(sprintf('vector component #%d is %s, not a number', $i + 1, get_debug_type($value)));
            }
            if (!is_finite((float) $value)) {
                throw new InvalidValueException(sprintf('vector component #%d is not a finite number', $i + 1));
            }
            $out[] = (float) $value;
        }

        return $out;
    }

    private static function errorText(mixed $body): string
    {
        if (is_array($body)) {
            foreach (['message', 'error'] as $field) {
                if (is_string($body[$field] ?? null)) {
                    return $body[$field];
                }
            }

            return Json::encode($body);
        }

        return is_string($body) ? $body : 'the server refused the frame';
    }

    private static function errorCode(mixed $body): ?string
    {
        return is_array($body) && is_string($body['code'] ?? null) && $body['code'] !== '' ? $body['code'] : null;
    }
}
