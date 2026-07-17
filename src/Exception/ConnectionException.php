<?php

declare(strict_types=1);

/**
 * Thrown when a connection to Redis cannot be established or is lost after retry.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Redis\Exception;

final class ConnectionException extends RedisException
{
    /**
     * @param string $message Error message
     * @param string $host Host that failed
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = '',
        private readonly string $host = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the host that failed to connect.
     *
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }
}
