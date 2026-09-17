<?php

declare(strict_types=1);

namespace TriCoreDb\Tests\Support;

use TriCoreDb\Client;

/**
 * A private `tricore-server` for the tests that need a real one.
 *
 * The binary is named by `TRICORE_SERVER_BIN`, or found in a sibling
 * `tricore/tricore-db/target/{release,debug}` checkout. It is never built here.
 * Without one, {@see LiveServer::skipReason()} says so and the live tests skip:
 * `composer test` must stay green for anyone without a server.
 */
final class LiveServer
{
    private const CONFIGURATION = <<<'TOML'
        [server]
        host = "127.0.0.1"
        port = 0
        protocol = "tricore"
        node_id = "sdk-php-tests"
        region_id = "local"

        [modules]
        sql = true
        document = true
        cache = true
        vector = true
        graph = true
        llm = true
        cluster = true

        [security]
        auth_mode = "password"
        dev_auth = true
        allow_default_admin = false

        [tls]
        enabled = false
        TOML;

    private static ?self $shared = null;

    /** @var resource */
    private $process;

    /** @var resource */
    private $output;

    private function __construct(
        private readonly string $directory,
        public readonly string $host,
        public readonly int $port
    ) {
    }

    /** Why the live tests cannot run, or null when they can. */
    public static function skipReason(): ?string
    {
        return self::binary() === null
            ? 'no tricore-server binary: set TRICORE_SERVER_BIN, or run one from the Docker image (see the README)'
            : null;
    }

    /** One server for the whole run, started on first use. */
    public static function shared(): self
    {
        return self::$shared ??= self::start();
    }

    /** Connect as `admin`. */
    public function connect(?float $readTimeout = null): Client
    {
        return Client::connect(
            host: $this->host,
            port: $this->port,
            user: 'admin',
            secret: 'pw',
            connectTimeout: 15.0,
            readTimeout: $readTimeout
        );
    }

    public function __destruct()
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        self::removeTree($this->directory);
    }

    private static function binary(): ?string
    {
        $named = getenv('TRICORE_SERVER_BIN');
        if (is_string($named) && $named !== '') {
            return is_executable($named) ? $named : null;
        }
        $directory = __DIR__;
        while (true) {
            foreach (['release', 'debug'] as $profile) {
                $candidate = $directory . '/target/' . $profile . '/tricore-server';
                if (is_executable($candidate)) {
                    return $candidate;
                }
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                return null;
            }
            $directory = $parent;
        }
    }

    private static function start(): self
    {
        $binary = self::binary() ?? throw new \RuntimeException('no tricore-server binary');
        $directory = sys_get_temp_dir() . '/tricoredb-php-' . bin2hex(random_bytes(6));
        mkdir($directory . '/data', 0700, true);
        file_put_contents($directory . '/tricore.toml', self::CONFIGURATION);

        $process = proc_open(
            [$binary, '--config', $directory . '/tricore.toml', '--port', '0', '--data-dir', $directory . '/data'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if ($process === false) {
            throw new \RuntimeException('could not start tricore-server');
        }
        $output = $pipes[1];

        // The address is read from the server's own line rather than assumed.
        $deadline = microtime(true) + 60;
        $address = null;
        stream_set_timeout($output, 1);
        while ($address === null && microtime(true) < $deadline) {
            $line = fgets($output);
            if ($line === false) {
                if (feof($output)) {
                    break;
                }
                continue;
            }
            $at = strpos($line, 'listening on ');
            if ($at !== false) {
                $address = trim(substr($line, $at + strlen('listening on ')));
            }
        }
        if ($address === null) {
            proc_terminate($process);
            throw new \RuntimeException('the server never said which address it listens on');
        }
        // Its later output is not read. It is quiet after start-up, and the pipe
        // buffer is far larger than anything these tests make it print.
        stream_set_blocking($output, false);

        $separator = strrpos($address, ':');
        $server = new self($directory, substr($address, 0, $separator), (int) substr($address, $separator + 1));
        $server->process = $process;
        $server->output = $output;

        return $server;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
