<?php

declare(strict_types=1);

namespace PHPdot\Redis\Tests\Integration;

use PHPdot\Redis\Config\RedisConfig;
use PHPdot\Redis\RedisConnection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration coverage against a live Redis. Run via
 * `composer test` when a server is available; the Unit suite covers the
 * disconnected-state contract without one.
 */
#[Group('integration')]
final class RedisConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        try {
            $probe = new RedisConnection(new RedisConfig(host: getenv('REDIS_HOST') ?: '127.0.0.1', port: (int) (getenv('REDIS_PORT') ?: 6379), timeout: 0.5, maxRetries: 0));
            $probe->connect();
            $probe->close();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not available: ' . $e->getMessage());
        }
    }

    #[Test]
    public function it_connects_to_redis(): void
    {
        $connection = new RedisConnection(new RedisConfig(host: getenv('REDIS_HOST') ?: '127.0.0.1', port: (int) (getenv('REDIS_PORT') ?: 6379)));
        $connection->connect();

        self::assertTrue($connection->isConnected());
        $connection->close();
    }

    #[Test]
    public function it_reports_not_connected_before_connect(): void
    {
        $connection = new RedisConnection(new RedisConfig(host: getenv('REDIS_HOST') ?: '127.0.0.1', port: (int) (getenv('REDIS_PORT') ?: 6379)));

        self::assertFalse($connection->isConnected());
    }

    #[Test]
    public function it_pings_the_server(): void
    {
        $connection = new RedisConnection(new RedisConfig(host: getenv('REDIS_HOST') ?: '127.0.0.1', port: (int) (getenv('REDIS_PORT') ?: 6379)));
        $connection->connect();

        self::assertTrue($connection->ping());
        $connection->close();
    }

    #[Test]
    public function it_exposes_the_underlying_client(): void
    {
        $connection = new RedisConnection(new RedisConfig(host: getenv('REDIS_HOST') ?: '127.0.0.1', port: (int) (getenv('REDIS_PORT') ?: 6379), database: 0));
        $connection->connect();

        $client = $connection->getClient();
        $client->set('phpdot:redis:integration', 'ok');

        self::assertSame('ok', $client->get('phpdot:redis:integration'));
        $connection->close();
    }

    #[Test]
    public function it_reconnects_after_close(): void
    {
        $connection = new RedisConnection(new RedisConfig(host: getenv('REDIS_HOST') ?: '127.0.0.1', port: (int) (getenv('REDIS_PORT') ?: 6379)));
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
        $connection = new RedisConnection(new RedisConfig(host: getenv('REDIS_HOST') ?: '127.0.0.1', port: (int) (getenv('REDIS_PORT') ?: 6379)));
        $connection->connect();

        $connection->close();

        self::assertFalse($connection->isConnected());
    }

    #[Test]
    public function it_resets_an_orphaned_transaction_before_reuse(): void
    {
        $connection = new RedisConnection(new RedisConfig(host: getenv('REDIS_HOST') ?: '127.0.0.1', port: (int) (getenv('REDIS_PORT') ?: 6379)));
        $connection->connect();

        $client = $connection->getClient();
        $client->multi();
        $client->set('phpdot_reset_test', 'queued');

        $connection->reset();

        self::assertTrue($connection->isConnected());
        $client = $connection->getClient();
        $client->set('phpdot_reset_test', 'after');
        self::assertSame('after', $client->get('phpdot_reset_test'));

        $client->del('phpdot_reset_test');
        $connection->close();
    }

    #[Test]
    public function it_restores_the_configured_database_after_a_runtime_select(): void
    {
        $connection = new RedisConnection(new RedisConfig(host: getenv('REDIS_HOST') ?: '127.0.0.1', port: (int) (getenv('REDIS_PORT') ?: 6379)));
        $connection->connect();

        $client = $connection->getClient();
        $client->select(1);

        $connection->reset();

        self::assertSame(0, $connection->getClient()->getDbNum());
        $connection->close();
    }

    #[Test]
    public function it_is_a_no_op_when_not_connected(): void
    {
        $connection = new RedisConnection(new RedisConfig(host: getenv('REDIS_HOST') ?: '127.0.0.1', port: (int) (getenv('REDIS_PORT') ?: 6379)));

        $connection->reset();

        self::assertFalse($connection->isConnected());
    }
}
