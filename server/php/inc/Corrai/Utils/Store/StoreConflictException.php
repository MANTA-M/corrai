<?php

namespace Corrai\Utils\Store;

/**
 * Raised when an If-Match conditional put fails (HTTP 412).
 */
class StoreConflictException extends \Exception
{
    public function __construct(string $message = 'Object store conflict', int $code = 412, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
