<?php

declare(strict_types=1);

namespace PHPdot\Redis\Tests\Integration;

use PHPdot\Redis\Config\RedisConfig;
use PHPdot\Redis\RedisConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration coverage against a live Redis (127.0.0.1:6379). Run via
 * `composer test` when a server is available; the Unit suite covers the
 * disconnected-state contract without one.
 */
final class RedisConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        try {
            $probe = new RedisConnection(new RedisConfig(timeout: 0.5, maxRetries: 0));
            $probe->connect();
            $probe->close();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not available: ' . $e->getMessage());
        }
    }

    #[Test]
    public function it_connects_to_redis(): void
    {
        $connection = new RedisConnection(new RedisConfig());
        $connection->connect();

        self::assertTrue($connection->isConnected());
        $connection->close();
    }

    #[Test]
    public function it_reports_not_connected_before_connect(): void
    {
        $connection = new RedisConnection(new RedisConfig());

        self::assertFalse($connection->isConnected());
    }

    #[Test]
    public function it_pings_the_server(): void
    {
        $connection = new RedisConnection(new RedisConfig());
        $connection->connect();

        self::assertTrue($connection->ping());
        $connection->close();
    }

    #[Test]
    public function it_exposes_the_underlying_client(): void
    {
        $connection = new RedisConnection(new RedisConfig(database: 0));
        $connection->connect();

        $client = $connection->getClient();
        $client->set('phpdot:redis:integration', 'ok');

        self::assertSame('ok', $client->get('phpdot:redis:integration'));
        $connection->close();
    }

    #[Test]
    public function it_reconnects_after_close(): void
    {
        $connection = new RedisConnection(new RedisConfig());
        $connection->connect();
        $connection->close();

        self::assertFalse($connection->isConnected());

        $connection->reconnect();
        self::assertTrue($connection->isConnected());
        $connection->close();
    }

    #[Test]
    public function it_closes_connection(): void
    {
        $connection = new RedisConnection(new RedisConfig());
        $connection->connect();

        $connection->close();

        self::assertFalse($connection->isConnected());
    }
}
