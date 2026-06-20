<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an optimistic-locking stock update is rejected because the
 * product's lock_version changed between read and write — i.e. another request
 * modified the same product concurrently. Maps to HTTP 409 Conflict.
 */
class StaleStockException extends RuntimeException
{
    public function __construct(
        public readonly int $productId,
        public readonly int $currentVersion,
        string $message = 'Stock was modified by another request. Reload and retry.',
    ) {
        parent::__construct($message);
    }
}
