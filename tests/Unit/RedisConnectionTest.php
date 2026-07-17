<?php

declare(strict_types=1);

namespace PHPdot\Redis\Tests\Unit;

use PHPdot\Redis\Config\RedisConfig;
use PHPdot\Redis\Exception\ConnectionException;
use PHPdot\Redis\RedisConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit-level coverage for RedisConnection. The `connect()` path requires a
 * live Redis and is exercised in the integration suite; here we cover the
 * disconnected-state contract: local lifecycle flags, config round-trip,
 * and the guard behaviour every method delegates to.
 */
final class RedisConnectionTest extends TestCase
{
    #[Test]
    public function it_starts_disconnected(): void
    {
        $connection = new RedisConnection(new RedisConfig());

        self::assertFalse($connection->isConnected());
    }

    #[Test]
    public function it_returns_config(): void
    {
        $config = new RedisConfig(database: 2);
        $connection = new RedisConnection($config);

        self::assertSame($config, $connection->getConfig());
        self::assertSame(2, $connection->getConfig()->database);
    }

    #[Test]
    public function it_throws_ensure_connected_when_not_connected(): void
    {
        $connection = new RedisConnection(new RedisConfig());

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected');
        $connection->ensureConnected();
    }

    #[Test]
    public function it_throws_get_client_when_not_connected(): void
    {
        $connection = new RedisConnection(new RedisConfig());

        $this->expectException(ConnectionException::class);
        $connection->getClient();
    }

    #[Test]
    public function it_returns_false_ping_when_not_connected(): void
    {
        $connection = new RedisConnection(new RedisConfig());

        self::assertFalse($connection->ping());
    }

    #[Test]
    public function it_close_is_idempotent(): void
    {
        $connection = new RedisConnection(new RedisConfig());

        // Close when not connected — should not throw
        $connection->close();
        $connection->close();

        self::assertFalse($connection->isConnected());
    }

    #[Test]
    public function it_connect_throws_connection_exception_when_unreachable(): void
    {
        // Point at a closed port so the retry loop exhausts and throws.
        $connection = new RedisConnection(new RedisConfig(
            host: '127.0.0.1',
            port: 1,
            timeout: 0.2,
            maxRetries: 0,
        ));

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Failed to connect after 1 attempts');
        $connection->connect();
    }

    #[Test]
    public function it_reconnect_throws_when_not_previously_connected_and_unreachable(): void
    {
        $connection = new RedisConnection(new RedisConfig(
            host: '127.0.0.1',
            port: 1,
            timeout: 0.2,
            maxRetries: 0,
        ));

        $this->expectException(ConnectionException::class);
        $connection->reconnect();
    }
}
