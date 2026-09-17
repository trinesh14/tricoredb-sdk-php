<?php

declare(strict_types=1);

namespace TriCoreDb;

use TriCoreDb\Exception\InvalidValueException;

/**
 * TLS settings for an `ssl://` connection.
 *
 * Certificates are verified by default. Without `caFile` PHP's configured
 * trust store (`openssl.cafile` / `openssl.capath`) is used.
 */
final class TlsOptions
{
    /**
     * @param string|null $caFile PEM CA bundle used to verify the server.
     * @param string|null $serverName Expected certificate name and SNI; defaults to the host dialled.
     * @param bool $dangerAcceptInvalidCerts Development only: skip certificate verification entirely.
     * @param string|null $clientCertFile PEM client certificate for mutual TLS.
     * @param string|null $clientKeyFile PEM private key for the client certificate.
     * @throws InvalidValueException When only one of the client certificate and key is given, or a file is unreadable.
     */
    public function __construct(
        public readonly ?string $caFile = null,
        public readonly ?string $serverName = null,
        public readonly bool $dangerAcceptInvalidCerts = false,
        public readonly ?string $clientCertFile = null,
        public readonly ?string $clientKeyFile = null
    ) {
        if (($clientCertFile === null) !== ($clientKeyFile === null)) {
            throw new InvalidValueException('mutual TLS needs both clientCertFile and clientKeyFile');
        }
        foreach (['caFile' => $caFile, 'clientCertFile' => $clientCertFile, 'clientKeyFile' => $clientKeyFile] as $label => $path) {
            if ($path !== null && !is_readable($path)) {
                throw new InvalidValueException(sprintf('tls %s `%s` is not readable', $label, $path));
            }
        }
    }

    /**
     * The `ssl` stream-context options for a connection to `$host`.
     *
     * @param string $host The host being dialled.
     * @return array<string, mixed>
     */
    public function contextOptions(string $host): array
    {
        $verify = !$this->dangerAcceptInvalidCerts;
        $options = [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'peer_name' => $this->serverName ?? $host,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
        if ($this->caFile !== null) {
            $options['cafile'] = $this->caFile;
        }
        if ($this->clientCertFile !== null) {
            $options['local_cert'] = $this->clientCertFile;
            $options['local_pk'] = $this->clientKeyFile;
        }

        return $options;
    }
}
