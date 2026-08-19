<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\World;

/**
 * Shared sidebar payload for the GM "world shell" (WorldLayout.vue). Every page rendered inside a
 * world — dashboard, players, compendium, import — sends this so the left nav stays identical.
 */
class WorldNav
{
    public static function for(World $world): array
    {
        $documents = $world->documents()->get(['id', 'kind', 'is_private']);

        return [
            'id' => $world->id,
            'name' => $world->name,
            'code' => $world->code, 'slug' => $world->slug,
            'visibility' => $world->visibility,
            'public_url' => url("/w/{$world->slug}"),
            'entries' => $documents->count(),
            'sections' => Sections::withCounts($documents),
            // Owner-only tools (co-authors, world settings) are hidden from invited editors.
            // Admin-granted feature access (off by default), gating the matching Tools links.
            'knowledge_ingestion_enabled' => (bool) $world->knowledge_ingestion_enabled,
            'is_owner' => auth()->id() === $world->user_id,
            // Who may reach world settings/fields/templates: the owner, or an admin (who bypasses the
            // policy) — so the settings nav shows for them even though they aren't the strict owner.
            'can_configure' => auth()->id() === $world->user_id || (bool) auth()->user()?->isAdmin(),
        ];
    }
}
