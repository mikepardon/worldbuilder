<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Document;
use App\Models\World;
use Illuminate\Support\Str;

/**
 * A compact textual digest of a world for grounding AI prompts: its style/setting, a short blurb, and
 * the entries that already exist (people, places, factions, lore…) so generated content stays
 * consistent with — and doesn't duplicate — what's there. Kept bounded so it fits comfortably in a
 * prompt: a capped number of titles per kind, each with a short summary when one exists.
 */
class WorldContext
{
    /** Kinds worth grounding a generator on, in the order they read best, mapped to a human label. */
    private const KINDS = [
        'location' => 'Locations',
        'npc' => 'People',
        'faction' => 'Factions',
        'lore' => 'Lore',
        'timeline' => 'Timelines',
        'item' => 'Items',
    ];

    public static function forPrompt(World $world, int $perKind = 12): string
    {
        $parts = [];

        $setting = trim((string) $world->setting);
        if ($setting !== '') {
            $parts[] = "Setting & style: {$setting}";
        }

        $description = trim((string) $world->description);
        if ($description !== '') {
            $parts[] = 'About this world: '.Str::limit($description, 500);
        }

        $documents = $world->documents()
            ->whereIn('kind', array_keys(self::KINDS))
            ->orderBy('title')
            ->get(['kind', 'title', 'summary']);

        foreach (self::KINDS as $kind => $label) {
            $group = $documents->where('kind', $kind);
            if ($group->isEmpty()) {
                continue;
            }

            $names = $group->take($perKind)->map(function (Document $document) {
                $summary = Str::limit(trim((string) $document->summary), 80, '…');

                return $summary !== '' ? "{$document->title} ({$summary})" : $document->title;
            })->implode('; ');

            $remaining = $group->count() - $perKind;
            $parts[] = "Existing {$label}: {$names}".($remaining > 0 ? " (+{$remaining} more)" : '');
        }

        return implode("\n", $parts);
    }
}
