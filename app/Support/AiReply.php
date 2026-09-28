<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A single model reply plus whether it was cut off by the output-token budget. Callers that need
 * complete output (e.g. a multi-page brew draft) use $truncated to decide whether to ask the model
 * to continue where it stopped.
 */
final readonly class AiReply
{
    public function __construct(
        public string $text,
        public bool $truncated,
    ) {}
}