<?php

declare(strict_types=1);

/**
 * Thrown when Redis authentication fails (wrong password, ACL denial, NOAUTH).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Redis\Exception;

final class AuthenticationException extends RedisException {}
