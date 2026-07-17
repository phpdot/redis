<?php

declare(strict_types=1);

/**
 * Base exception for all PHPdot Redis exceptions.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Redis\Exception;

use RuntimeException;

class RedisException extends RuntimeException
{
    /**
     * @param string $message Error message
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
