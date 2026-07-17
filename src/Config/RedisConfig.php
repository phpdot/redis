<?php

declare(strict_types=1);

/**
 * Immutable configuration for a Redis connection.
 *
 * Maps cleanly onto the ext-redis `\Redis::connect()` signature:
 * `connect($host, $port, $timeout, $persistent_id, $retry_interval,
 * $read_timeout, $context)`. When TLS is enabled the host is prefixed with
 * `tls://` (or a unix socket path is used directly when `$path` is set).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Redis\Config;

use PHPdot\Container\Attribute\Config;

#[Config('redis')]
final readonly class RedisConfig
{
    /**
     * Immutable Redis connection settings passed to ext-redis' connect call.
     *
     * @param string $host Redis hostname or IP (ignored when $path is set)
     * @param int $port Redis TCP port (ignored when $path is set)
     * @param string $path Unix domain socket path; when non-empty, supersedes host/port
     * @param string $password AUTH password (Redis 5-). Empty skips AUTH.
     * @param string $username ACL username (Redis 6+). Empty uses legacy password-only AUTH.
     * @param int $database Database index to SELECT after connecting
     * @param float $timeout Connect timeout in seconds (0 = no timeout)
     * @param int $retryInterval Reconnect delay in milliseconds applied by ext-redis
     * @param float $readTimeout Read timeout in seconds (0 = no timeout)
     * @param bool $tls Connect over TLS (prefixes the host with `tls://`)
     * @param array<string, mixed> $ssl Stream SSL context options (CA, cert, verify, …)
     * @param int $maxRetries Connection-attempt retries with exponential backoff
     * @param bool $persistent Use a persistent connection (pconnect). Off by
     *                         default so a connection pool owns the lifecycle
     *                         and one `\Redis` lives per coroutine.
     * @param array<string, mixed> $context Catch-all passed as ext-redis' $context arg
     */
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 6379,
        public string $path = '',
        public string $password = '',
        public string $username = '',
        public int $database = 0,
        public float $timeout = 0.0,
        public int $retryInterval = 0,
        public float $readTimeout = 0.0,
        public bool $tls = false,
        public array $ssl = [],
        public int $maxRetries = 3,
        public bool $persistent = false,
        public array $context = [],
    ) {}

    /**
     * Host string for ext-redis: the unix socket path verbatim, or the TCP
     * host optionally prefixed with `tls://` when TLS is requested.
     *
     * @return string
     */
    public function connectHost(): string
    {
        if ($this->path !== '') {
            return $this->path;
        }

        return $this->tls ? 'tls://' . $this->host : $this->host;
    }

    /**
     * Stream context passed to ext-redis. Merges any SSL options into the
     * catch-all `$context` under a `stream` key when TLS is on.
     *
     * Matches the shape ext-redis' connect()/pconnect() accept:
     * `array{auth?: array{0: string|false|null, 1?: string}, stream?: array<string, mixed>}`.
     *
     * @return array{auth?: array{0: string|false|null, 1?: string}, stream?: array<string, mixed>}|null
     */
    public function buildContext(): ?array
    {
        if ($this->ssl === [] && $this->context === []) {
            return null;
        }

        /**
         * @var array{auth?: array{0: string|false|null, 1?: string}, stream?: array<string, mixed>} $context
         */
        $context = $this->context;

        if ($this->ssl !== []) {
            $stream = $context['stream'] ?? [];
            $context['stream'] = array_merge($stream, $this->ssl);
        }

        return $context;
    }

    /**
     * Get the host string for error messages (socket path or host:port).
     *
     * @return string
     */
    public function getHostString(): string
    {
        if ($this->path !== '') {
            return $this->path;
        }

        return $this->host . ':' . $this->port;
    }
}
