<?php

declare(strict_types=1);

namespace PHPdot\Redis\Tests\Integration;

use PHPdot\Redis\Config\RedisConfig;
use PHPdot\Redis\Exception\AuthenticationException;
use PHPdot\Redis\RedisConnection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Authentication coverage against the passworded redis-auth compose service.
 * The main redis service stays open, so these are the only tests exercising
 * AUTH: correct credentials connect, and the WRONGPASS / NOAUTH replies both
 * surface as AuthenticationException rather than a generic connection error.
 */
#[Group('integration')]
final class RedisAuthTest extends TestCase
{
    private string $host;
    private int $port;
    private string $password;

    protected function setUp(): void
    {
        $this->host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $this->port = (int) (getenv('REDIS_AUTH_PORT') ?: 6381);
        $this->password = getenv('REDIS_AUTH_PASS') ?: 'phpdot-secret';

        try {
            $probe = new RedisConnection(new RedisConfig(host: $this->host, port: $this->port, password: $this->password, timeout: 0.5, maxRetries: 0));
            $probe->connect();
            $probe->close();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Passworded Redis is not available: ' . $e->getMessage());
        }
    }

    #[Test]
    public function it_connects_with_the_correct_password(): void
    {
        $connection = new RedisConnection(new RedisConfig(host: $this->host, port: $this->port, password: $this->password, maxRetries: 0));
        $connection->connect();

        self::assertTrue($connection->ping());
        $connection->close();
    }

    #[Test]
    public function it_rejects_a_wrong_password_as_an_authentication_failure(): void
    {
        $connection = new RedisConnection(new RedisConfig(host: $this->host, port: $this->port, password: 'not-the-password', maxRetries: 0));

        $this->expectException(AuthenticationException::class);

        $connection->connect();
    }

    #[Test]
    public function it_rejects_missing_credentials_as_an_authentication_failure(): void
    {
        $connection = new RedisConnection(new RedisConfig(host: $this->host, port: $this->port, maxRetries: 0));

        $this->expectException(AuthenticationException::class);

        $connection->connect();
    }
}
