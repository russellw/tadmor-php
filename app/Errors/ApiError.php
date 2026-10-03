<?php

namespace App\Errors;

use RuntimeException;

/**
 * A refusal with its HTTP status (spec/api.md §1.4). Services throw it; the
 * API renders it as {"error": message} and the UI shows the message.
 */
class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
