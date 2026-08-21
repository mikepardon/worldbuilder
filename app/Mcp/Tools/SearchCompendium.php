<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\CampaignCompendiumItem;
use App\Models\World;
use App\Support\Compendium;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Search a world\'s compendium (monsters, spells, items and other entries) by name. Returns matching entries with their IDs.')]
class SearchCompendium extends Tool
{
    public function handle(Request $request): Response
    {
        $user = $request->user();
        if ($user === null) {
            return Response::error('You must be authenticated to use this tool.');
        }

        $validated = $request->validate([
            'world_id' => ['required', 'integer'],
            'query' => ['required', 'string', 'min:1', 'max:160'],
            'type' => ['nullable', 'in:'.implode(',', Compendium::keys())],
        ]);

        $world = World::find($validated['world_id']);

        // Deliberately do not distinguish "no access" from "not found" — that would leak which world IDs exist.
        if ($world === null || ! $world->isManagedBy($user)) {
            return Response::error('World not found.');
        }

        $items = $world->compendiumItems()
            ->search($validated['query'])
            ->when(
                $validated['type'] ?? null,
                fn (Builder $query, string $type) => $query->where('item_type', $type),
            )
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'slug', 'item_type', 'summary'])
            ->map(fn (CampaignCompendiumItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'type' => $item->item_type,
                'summary' => $item->summary,
            ])->all();

        return Response::text((string) json_encode(['items' => $items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'world_id' => $schema->integer()
                ->description('The ID of the world to search (from list-worlds).')
                ->required(),
            'query' => $schema->string()
                ->description('Text to match against entry names and summaries.')
                ->required(),
            'type' => $schema->string()
                ->description('Optionally restrict results to a single entry type.')
                ->enum(Compendium::keys()),
        ];
    }
}
