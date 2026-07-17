<?php

declare(strict_types=1);

/**
 * Coroutine-safe Redis connection wrapper around ext-redis `\Redis`.
 *
 * One `RedisConnection` owns one `\Redis` socket. Under Swoole, do not share
 * a single connection across concurrent coroutines — a connection pool (e.g.
 * `phpdot/pool` with `RedisConnector`) borrows a fresh connection per
 * coroutine so no two coroutines interleave commands on one socket. Use
 * {@see getClient()} to reach the underlying `\Redis` for commands.
 *
 * Reconnects with exponential backoff and translates driver exceptions into
 * the `PHPdot\Redis\Exception\*` hierarchy. Mirrors `phpdot/mongodb`'s
 * `MongoConnection` lifecycle: `connect()`, `isConnected()`, `ping()`,
 * `ensureConnected()`, `reconnect()`, `close()`, `getClient()`.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Redis;

use PHPdot\Redis\Config\RedisConfig;
use PHPdot\Redis\Exception\AuthenticationException;
use PHPdot\Redis\Exception\ConnectionException;
use Redis as RedisClient;
use RedisException;

final class RedisConnection
{
    private ?RedisClient $client = null;

    private bool $connected = false;

    /**
     * Hold the settings for a single, not-yet-opened Redis connection.
     *
     * @param RedisConfig $config Connection configuration
     */
    public function __construct(
        private readonly RedisConfig $config,
    ) {}

    /**
     * Connect to Redis with exponential backoff retries.
     *
     * Runs AUTH (password, or ACL user+password) and SELECT when configured,
     * then PINGs to confirm the connection is usable.
     *
     * @throws ConnectionException If connection fails after all retries
     * @throws AuthenticationException If authentication fails
     *
     * @return void
     */
    public function connect(): void
    {
        $lastException = null;
        $maxRetries = $this->config->maxRetries;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                $delay = 100 * (2 ** ($attempt - 1));
                usleep($delay * 1000);
            }

            try {
                $client = $this->createClient();
                $this->authenticate($client);
                $this->selectDatabase($client);
                $client->ping();
                $this->client = $client;
                $this->connected = true;

                return;
            } catch (RedisException $e) {
                if ($this->isAuthError($e)) {
                    throw new AuthenticationException(
                        'Authentication failed: ' . $e->getMessage(),
                        $e->getCode(),
                        $e,
                    );
                }

                $lastException = $e;
                $this->client = null;
                $this->connected = false;
            }
        }

        throw new ConnectionException(
            'Failed to connect after ' . ($maxRetries + 1) . ' attempts: '
            . ($lastException?->getMessage() ?? 'unknown error'),
            $this->config->getHostString(),
            $lastException?->getCode() ?? 0,
            $lastException,
        );
    }

    /**
     * Close the connection. Safe to call when already closed.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->client !== null) {
            try {
                $this->client->close();
            } catch (RedisException) {
            }
        }

        $this->client = null;
        $this->connected = false;
    }

    /**
     * Check if the connection is established (local flag, no server ping).
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Ping the server to verify the connection is alive.
     *
     * @return bool
     */
    public function ping(): bool
    {
        if ($this->client === null) {
            return false;
        }

        try {
            $reply = $this->client->ping();

            return $reply === '+PONG' || $reply === true || $reply === $this->client;
        } catch (RedisException) {
            $this->connected = false;

            return false;
        }
    }

    /**
     * Ensure the connection is established. Checks the local flag only.
     *
     * @throws ConnectionException If not connected
     *
     * @return void
     */
    public function ensureConnected(): void
    {
        if (!$this->connected || $this->client === null) {
            throw new ConnectionException(
                'Not connected to Redis',
                $this->config->getHostString(),
            );
        }
    }

    /**
     * Close and re-establish the connection.
     *
     * @throws ConnectionException If reconnection fails
     * @throws AuthenticationException If authentication fails on reconnect
     *
     * @return void
     */
    public function reconnect(): void
    {
        $this->close();
        $this->connect();
    }

    /**
     * Scrub per-borrow state so a connection returned to the pool is safe for
     * the next coroutine to borrow.
     *
     * Mirrors {@see \PHPdot\Database\DatabaseConnection::reset()}. Two pieces
     * of state can leak across borrowers:
     *
     *   1. An in-flight MULTI/EXEC transaction — if a coroutine queues commands
     *      but never EXECs (or dies), the next borrower's commands would silently
     *      append to the orphaned transaction. DISCARD aborts it.
     *   2. A runtime SELECT to a different database index — if a coroutine
     *      switched DBs mid-request, the next borrower would write to the wrong
     *      keyspace. SELECT restores the configured DB.
     *
     * Should be called from the pool's release callback, before the connection
     * is handed back. Safe to call when not connected (no-op).
     *
     * @return void
     */
    public function reset(): void
    {
        if ($this->client === null) {
            return;
        }

        try {
            $this->client->discard();
            $this->selectDatabase($this->client, force: true);
        } catch (RedisException) {
            $this->connected = false;
        }
    }

    /**
     * Get the underlying ext-redis `\Redis` for issuing commands.
     *
     * This is the seam consumers (cache, session, redis-ql, application code)
     * use. It is the only non-lifecycle method on the wrapper, mirroring
     * `MongoConnection::getClient()`.
     *
     * @throws ConnectionException If not connected
     *
     * @return RedisClient
     */
    public function getClient(): RedisClient
    {
        $this->ensureConnected();
        assert($this->client !== null);

        return $this->client;
    }

    /**
     * Get the connection configuration.
     *
     * @return RedisConfig
     */
    public function getConfig(): RedisConfig
    {
        return $this->config;
    }

    /**
     * Build a fresh ext-redis `\Redis` and establish the socket connection.
     *
     * @return RedisClient
     */
    private function createClient(): RedisClient
    {
        $client = new RedisClient();
        $config = $this->config;

        if ($config->persistent) {
            $client->pconnect(
                $config->connectHost(),
                $config->path !== '' ? -1 : $config->port,
                $config->timeout,
                null,
                $config->retryInterval,
                $config->readTimeout,
                $config->buildContext(),
            );
        } else {
            $client->connect(
                $config->connectHost(),
                $config->path !== '' ? -1 : $config->port,
                $config->timeout,
                null,
                $config->retryInterval,
                $config->readTimeout,
                $config->buildContext(),
            );
        }

        return $client;
    }

    /**
     * Run AUTH when credentials are configured. ACL username (Redis 6+) uses
     * the two-arg form; legacy password-only uses the single-arg form.
     *
     * @param RedisClient $client
     *
     * @throws RedisException If the server rejects the credentials
     *
     * @return void
     */
    private function authenticate(RedisClient $client): void
    {
        if ($this->config->password === '') {
            return;
        }

        if ($this->config->username !== '') {
            $client->auth([$this->config->username, $this->config->password]);
        } else {
            $client->auth($this->config->password);
        }
    }

    /**
     * SELECT the configured database index.
     *
     * @param bool $force When false (the connect path), skip the round-trip if
     *                    the configured DB is 0 — a fresh connection is already
     *                    on db 0, so SELECT 0 is redundant. When true (the reset
     *                    path), always SELECT: the borrower may have switched to
     *                    a different DB mid-request, and only an explicit SELECT
     *                    restores the configured one.
     * @param RedisClient $client
     *
     * @return void
     */
    private function selectDatabase(RedisClient $client, bool $force = false): void
    {
        if ($force || $this->config->database !== 0) {
            $client->select($this->config->database);
        }
    }

    /**
     * Whether a driver exception represents an authentication failure, so it
     * maps to {@see AuthenticationException} instead of the retryable
     * {@see ConnectionException}.
     *
     * ext-redis 6.x throws a bare {@see RedisException} with no typed subclass
     * and error code `0` for every failure — the only stable signal is the
     * message, which carries Redis' RESP error-type prefix verbatim. The RESP
     * spec (redis.io/commands/auth) defines two auth-class prefixes:
     * `WRONGPASS` (invalid credentials) and `NOAUTH` (credentials required but
     * not supplied). Both are part of the wire contract and stable across
     * Redis versions; matching the prefix (not a substring) avoids false
     * positives from unrelated errors that happen to mention these words.
     *
     * @param RedisException $e
     *
     * @return bool
     */
    private function isAuthError(RedisException $e): bool
    {
        $message = $e->getMessage();

        return str_starts_with($message, 'WRONGPASS')
            || str_starts_with($message, 'NOAUTH');
    }
}
