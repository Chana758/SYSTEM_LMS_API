<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business-rule failure (duplicate, limit reached, wrong status ...).
 * Laravel calls render() automatically, so controllers do not need try/catch
 * and the front-end still receives { "message": "..." } with a 4xx status.
 */
class ReservationException extends RuntimeException
{
    public function __construct(string $message, protected int $status = 422)
    {
        parent::__construct($message);
    }

    public function render()
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}