<?php

declare(strict_types=1);

namespace TriCoreDb\Tests\Support;

use TriCoreDb\Client;
use TriCoreDb\Protocol\FrameTag;

/**
 * A peer that speaks the handshake and then plays a scripted answer.
 *
 * A real server cannot be asked to answer `not_leader` on demand, to hang up
 * mid-frame, or to declare a payload it does not send. What is under test is
 * this client's reading of those answers, and the shape it reads is the one a
 * real cluster sends.
 */
final class ScriptedPeer
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    public readonly int $port;

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function __construct(array $steps)
    {
        $script = base64_encode(json_encode($steps, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/peer.php', $script],
            [1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'w']],
            $pipes
        );
        if ($process === false) {
            throw new \RuntimeException('could not start the scripted peer');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        $line = fgets($pipes[1]);
        if ($line === false || !ctype_digit(trim($line))) {
            throw new \RuntimeException('the scripted peer did not say which port it listens on');
        }
        $this->port = (int) trim($line);
    }

    public function __destruct()
    {
        $this->shutdown();
    }

    /**
     * A peer that grants `$features` and then runs `$steps`.
     *
     * @param list<array<string, mixed>> $steps
     */
    public static function start(array $steps, int $features = 7): self
    {
        return new self(array_merge([
            self::reply(FrameTag::HELLO_OK, [
                'ok' => true,
                'server_version' => ['major' => 1, 'minor' => 0],
                'message' => 'ok',
                'features' => $features,
            ]),
            self::reply(FrameTag::AUTH_OK, ['ok' => true, 'session_id' => 's-1']),
        ], $steps));
    }

    /**
     * A peer that runs `$steps` from the first frame, handshake included.
     *
     * @param list<array<string, mixed>> $steps
     */
    public static function startRaw(array $steps): self
    {
        return new self($steps);
    }

    /**
     * @return array{reply: array{0: int, 1: mixed}}
     */
    public static function reply(int $tag, mixed $json): array
    {
        return ['reply' => [$tag, $json]];
    }

    /**
     * @param array<string, mixed> $json
     * @return array{reply: array{0: int, 1: mixed}}
     */
    public static function response(array $json): array
    {
        return self::reply(FrameTag::RESPONSE, $json);
    }

    /**
     * @return array{raw: string}
     */
    public static function raw(string $bytes): array
    {
        return ['raw' => base64_encode($bytes)];
    }

    /**
     * @return array{hangup: true}
     */
    public static function hangUp(): array
    {
        return ['hangup' => true];
    }

    /**
     * @return array{silence: true}
     */
    public static function silence(): array
    {
        return ['silence' => true];
    }

    /** Connect a client to this peer. */
    public function connect(?float $readTimeout = null, ?string $user = 'admin'): Client
    {
        return Client::connect(
            host: '127.0.0.1',
            port: $this->port,
            user: $user,
            secret: 'pw',
            connectTimeout: 5.0,
            readTimeout: $readTimeout
        );
    }

    /** Stop the peer process. */
    public function shutdown(): void
    {
        if (!isset($this->process) || !is_resource($this->process)) {
            return;
        }
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
