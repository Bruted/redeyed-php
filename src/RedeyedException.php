<?php

namespace Redeyed;

use RuntimeException;

/**
 * Thrown for any non-2xx response or {error} envelope from the Redeyed API,
 * and for client-side failures (missing key, network error).
 */
class RedeyedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'error',
        public readonly int $status = 0,
        public readonly ?array $meta = null,
    ) {
        parent::__construct($message);
    }
}
