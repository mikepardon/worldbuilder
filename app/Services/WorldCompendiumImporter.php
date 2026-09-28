<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompendiumItem;
use App\Models\World;
use Illuminate\Support\Facades\DB;

/**
 * Copies entries from the global compendium library into a world's own compendium as locked, read-only
 * copies. Idempotent — matched by (item_type, slug) — so re-importing refreshes an existing imported
 * copy but never clobbers a custom/cloned entry a GM has edited. Used by the manual library import, by a
 * rule system's "import prerequisites" action, and when a template system is cloned into a world.
 */
class WorldCompendiumImporter
{
    /**
     * Import every entry belonging to the given library sources.
     *
     * @param  list<int>  $sourceIds
     * @return array{imported: int, refreshed: int, map: array<int, int>}
     */
    public function importSources(World $world, array $sourceIds, int $authorId): array
    {
        if ($sourceIds === []) {
            return ['imported' => 0, 'refreshed' => 0, 'map' => []];
        }

        $itemIds = CompendiumItem::query()->whereIn('source_id', $sourceIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return $this->importItems($world, $itemIds, $authorId);
    }

    /**
     * Import specific global-library entries by id.
     *
     * @param  list<int>  $globalItemIds
     * @return array{imported: int, refreshed: int, map: array<int, int>} the map is global item id → the world entry id
     */
    public function importItems(World $world, array $globalItemIds, int $authorId): array
    {
        $globalItemIds = array_values(array_unique(array_map('intval', $globalItemIds)));
        if ($globalItemIds === []) {
            return ['imported' => 0, 'refreshed' => 0, 'map' => []];
        }

        $sources = CompendiumItem::query()->whereIn('id', $globalItemIds)->with('source')->get();
        $imported = 0;
        $refreshed = 0;

        DB::transaction(function () use ($world, $sources, $authorId, &$imported, &$refreshed): void {
            foreach ($sources as $source) {
                $content = [
                    'name' => $source->name,
                    'summary' => $source->summary,
                    'document' => $source->document,
                    'fields' => $source->fields,
                    'data' => $source->data,
                    'source_item_id' => $source->id,
                    'origin' => $source->source?->provider, // open5e | dnd5eapi | …
                ];

                $existing = $world->compendiumItems()
                    ->where('item_type', $source->item_type)
                    ->where('slug', $source->slug)
                    ->first();

                if ($existing !== null) {
                    if ($existing->provider === 'imported') {
                        $existing->update($content);
                        $refreshed++;
                    }

                    continue;
                }

                $world->compendiumItems()->create([
                    'user_id' => $authorId,
                    'item_type' => $source->item_type,
                    'slug' => $source->slug,
                    ...$content,
                    'provider' => 'imported',
                    'is_private' => false,
                    'is_active' => true,
                ]);
                $imported++;
            }
        });

        // The map covers every referenced global entry now present in the world (freshly imported or
        // already there), so callers can remap references onto the world copies.
        $map = $world->compendiumItems()
            ->whereIn('source_item_id', $globalItemIds)
            ->pluck('id', 'source_item_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return ['imported' => $imported, 'refreshed' => $refreshed, 'map' => $map];
    }
}
