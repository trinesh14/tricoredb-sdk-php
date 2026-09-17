<?php

declare(strict_types=1);

namespace TriCoreDb\Protocol;

use TriCoreDb\Exception\ConnectionException;
use TriCoreDb\Exception\InvalidValueException;
use TriCoreDb\Exception\ProtocolException;
use TriCoreDb\Exception\TimeoutException;
use TriCoreDb\Internal\Json;
use TriCoreDb\TlsOptions;

/**
 * A framed, blocking byte stream over a PHP stream resource.
 *
 * Any failure that leaves the stream at an unknown position (a timeout, a
 * short read, an unreadable header or body) closes the stream before the
 * exception is thrown: a length-prefixed stream cannot be resynchronised.
 *
 * @internal
 */
final class Transport
{
    /** Used as "no read timeout": PHP socket streams otherwise inherit default_socket_timeout. */
    private const NO_TIMEOUT_SECONDS = 31536000;

    private const CHUNK = 65536;

    /** @var resource|null */
    private $stream;

    private ?float $readTimeout = null;

    /**
     * @param resource $stream A connected, blocking stream.
     */
    public function __construct($stream)
    {
        if (!is_resource($stream)) {
            throw new InvalidValueException('a transport needs an open stream resource');
        }
        $this->stream = $stream;
        stream_set_blocking($stream, true);
        $this->applyReadTimeout();
    }

    /**
     * Dial a server over `tcp://` or, with TLS options, `ssl://`.
     *
     * @param string $host Host name or IP address.
     * @param int $port TCP port.
     * @param float $connectTimeout Seconds to wait for the TCP (and TLS) handshake.
     * @param TlsOptions|null $tls TLS settings, or null for plaintext.
     * @throws ConnectionException When the connection cannot be established.
     */
    public static function open(string $host, int $port, float $connectTimeout, ?TlsOptions $tls): self
    {
        $options = ['socket' => ['tcp_nodelay' => true]];
        if ($tls !== null) {
            $options['ssl'] = $tls->contextOptions($host);
        }
        $context = stream_context_create($options);
        $target = str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
        $address = sprintf('%s://%s:%d', $tls === null ? 'tcp' : 'ssl', $target, $port);
        $timeout = $connectTimeout > 0 ? $connectTimeout : (float) ini_get('default_socket_timeout');

        $errno = 0;
        $errstr = '';
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $stream = stream_socket_client($address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }
        if ($stream === false) {
            $detail = $errstr !== '' ? $errstr : ($warning ?? 'unknown error');

            throw new ConnectionException(sprintf('connect to %s:%d failed: %s', $host, $port, $detail));
        }

        return new self($stream);
    }

    /**
     * Set how long a read may block, in seconds; null waits indefinitely.
     */
    public function setReadTimeout(?float $seconds): void
    {
        $this->readTimeout = $seconds !== null && $seconds > 0 ? $seconds : null;
        $this->applyReadTimeout();
    }

    /** Whether the stream is still open. */
    public function isOpen(): bool
    {
        return $this->stream !== null && is_resource($this->stream);
    }

    /**
     * Encode and write one frame. A payload that cannot be encoded, or is too
     * large, is refused before a byte is written and leaves the stream usable.
     *
     * @param int $tag Frame tag.
     * @param mixed $payload A JSON-encodable payload, or null for an empty body.
     */
    public function send(int $tag, mixed $payload): void
    {
        $body = $payload === null ? '' : Json::encode($payload);
        $this->write(Frame::encode($tag, $body));
    }

    /**
     * Read one frame.
     *
     * @return array{0: int, 1: mixed} The tag and the decoded JSON body (null when empty).
     */
    public function receive(): array
    {
        $header = $this->readExactly(Frame::HEADER_SIZE);
        try {
            $parts = Frame::decodeHeader($header);
        } catch (ProtocolException $e) {
            $this->close();

            throw $e;
        }
        if ($parts['length'] === 0) {
            return [$parts['tag'], null];
        }
        $body = $this->readExactly($parts['length']);
        try {
            return [$parts['tag'], Json::decode($body)];
        } catch (ProtocolException $e) {
            $this->close();

            throw $e;
        }
    }

    /** Close the stream. Safe to call more than once. */
    public function close(): void
    {
        if ($this->stream !== null) {
            if (is_resource($this->stream)) {
                @fclose($this->stream);
            }
            $this->stream = null;
        }
    }

    private function applyReadTimeout(): void
    {
        if (!$this->isOpen()) {
            return;
        }
        $seconds = $this->readTimeout ?? (float) self::NO_TIMEOUT_SECONDS;
        $whole = (int) floor($seconds);
        stream_set_timeout($this->stream, $whole, (int) round(($seconds - $whole) * 1_000_000));
    }

    /**
     * @return resource
     */
    private function requireOpen()
    {
        if (!$this->isOpen()) {
            throw new ConnectionException('the connection is closed');
        }

        return $this->stream;
    }

    private function write(string $bytes): void
    {
        $stream = $this->requireOpen();
        $length = strlen($bytes);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($stream, substr($bytes, $offset, self::CHUNK));
            if ($written === false || $written === 0) {
                $timedOut = (bool) (stream_get_meta_data($stream)['timed_out'] ?? false);
                $this->close();
                if ($timedOut) {
                    throw new TimeoutException('timed out while sending a frame; the connection is closed');
                }

                throw new ConnectionException('the connection closed while sending a frame');
            }
            $offset += $written;
        }
    }

    private function readExactly(int $length): string
    {
        $stream = $this->requireOpen();
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = @fread($stream, min($length - strlen($buffer), self::CHUNK));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($stream);
                if (($meta['timed_out'] ?? false) === true) {
                    $this->close();

                    throw new TimeoutException(sprintf(
                        'no reply within %s seconds; the connection is closed because the late reply '
                        . 'would otherwise be read as the answer to the next request',
                        $this->readTimeout === null ? 'the' : rtrim(rtrim(sprintf('%.3f', $this->readTimeout), '0'), '.')
                    ));
                }
                if ($chunk === '' && !feof($stream)) {
                    continue;
                }
                $this->close();

                throw new ConnectionException($buffer === '' && $length === Frame::HEADER_SIZE
                    ? 'the server closed the connection'
                    : 'the connection closed mid-frame');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }
}
