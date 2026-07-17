<?php

declare(strict_types=1);

namespace PHPdot\Redis\Tests\Unit;

use PHPdot\Contracts\Pool\ConnectorInterface;
use PHPdot\Redis\Config\RedisConfig;
use PHPdot\Redis\RedisConnection;
use PHPdot\Redis\RedisConnector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit-level coverage for RedisConnector. The `connect()` path requires a
 * live Redis and is exercised in the integration suite; here we cover the
 * contract guarantees: interface implementation, type-guard rejection,
 * silent failure on close.
 */
final class RedisConnectorTest extends TestCase
{
    private RedisConnector $connector;

    protected function setUp(): void
    {
        $this->connector = new RedisConnector(new RedisConfig());
    }

    #[Test]
    public function it_implements_the_pool_connector_contract(): void
    {
        self::assertInstanceOf(ConnectorInterface::class, $this->connector);
    }

    #[Test]
    public function is_alive_rejects_foreign_objects(): void
    {
        self::assertFalse($this->connector->isAlive(new stdClass()));
    }

    #[Test]
    public function is_alive_returns_false_for_a_disconnected_connection(): void
    {
        $connection = new RedisConnection(new RedisConfig());

        self::assertFalse($this->connector->isAlive($connection));
    }

    #[Test]
    public function close_is_a_no_op_for_foreign_objects(): void
    {
        $this->connector->close(new stdClass());
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function close_swallows_errors_from_the_connection(): void
    {
        $connection = new RedisConnection(new RedisConfig());

        $this->connector->close($connection);
        $this->expectNotToPerformAssertions();
    }
}
