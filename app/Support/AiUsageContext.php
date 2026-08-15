<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Context a caller passes to AnthropicClient so a metered AI call can be logged: which feature it was,
 * for which world/user, and an optional detail (e.g. the generator kind or compendium item type).
 */
final readonly class AiUsageContext
{
    public function __construct(
        public string $feature,
        public ?int $worldId = null,
        public ?int $userId = null,
        public ?string $detail = null,
    ) {}
}
