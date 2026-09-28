<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a talent allocation, deallocation or enhancement would break a rule system's rules
 * (budget, connectivity, level gate, kind cap, requirements). Carries a user-facing message and a
 * machine-readable reason so controllers can return a clean 422.
 */
class TalentAllocationException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
