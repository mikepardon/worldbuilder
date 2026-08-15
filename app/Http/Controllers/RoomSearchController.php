<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CampaignCompendiumItem;
use App\Models\Document;
use App\Models\Room;
use App\Support\Compendium;
use App\Support\Sections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The GM's token picker search: monsters straight from this world's compendium (filtered by source and
 * challenge rating) or People entries. Returns a normalised result the "Add token" modal can drop
 * onto the board.
 */
class RoomSearchController extends Controller
{
    public function __invoke(Request $request, Room $room): JsonResponse
    {
        $this->authorize('manage', $room->campaign);

        $data = $request->validate([
            'kind' => ['nullable', 'in:monster,person'],
            'q' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'in:all,core,worldbuilder,ddb'],
            'min_cr' => ['nullable', 'numeric'],
            'max_cr' => ['nullable', 'numeric'],
        ]);

        $query = trim($data['q'] ?? '');

        return response()->json([
            'results' => ($data['kind'] ?? 'monster') === 'person'
                ? $this->people($room, $query)
                : $this->monsters($room, $query, $data),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function monsters(Room $room, string $query, array $data): array
    {
        $builder = $room->campaign->world->compendiumItems()->with('image')->where('item_type', 'monster');

        if ($query !== '') {
            $builder->search($query);
        }

        match ($data['source'] ?? 'all') {
            'core' => $builder->where('provider', 'imported'),
            'worldbuilder' => $builder->where('provider', 'custom')->whereNull('origin'),
            'ddb' => $builder->where('origin', 'ddb'),
            default => null,
        };

        $min = isset($data['min_cr']) ? (float) $data['min_cr'] : null;
        $max = isset($data['max_cr']) ? (float) $data['max_cr'] : null;
        $filterCr = $min !== null || $max !== null;

        return $builder->orderBy('name')->limit(500)->get()
            ->map(function (CampaignCompendiumItem $item) {
                $cr = (string) data_get($item->fields, 'block.cr', '');

                return [
                    'type' => 'monster',
                    'id' => $item->id,
                    'name' => $item->name,
                    'image_url' => $item->image?->url,
                    'cr' => $cr,
                    'cr_value' => $this->crValue($cr),
                    'source' => Compendium::sourceLabel($item->origin, (string) $item->provider),
                    'block' => data_get($item->fields, 'block'),
                ];
            })
            ->filter(function (array $result) use ($filterCr, $min, $max) {
                if (! $filterCr) {
                    return true;
                }
                $value = $result['cr_value'];

                return $value !== null && ($min === null || $value >= $min) && ($max === null || $value <= $max);
            })
            ->take(60)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function people(Room $room, string $query): array
    {
        $builder = $room->campaign->world->documents()->where('kind', 'npc');

        if ($query !== '') {
            $like = '%'.mb_strtolower($query).'%';
            $builder->where(fn ($inner) => $inner
                ->whereRaw('LOWER(title) LIKE ?', [$like])
                ->orWhereRaw('LOWER(summary) LIKE ?', [$like]));
        }

        return $builder->orderBy('title')->limit(60)->get(['id', 'title', 'summary', 'slug', 'kind'])
            ->map(fn (Document $document) => [
                'type' => 'person',
                'id' => $document->id,
                'name' => $document->title,
                'image_url' => null,
                'cr' => '',
                'cr_value' => null,
                'source' => 'Worldbuilder',
                'summary' => $document->summary,
                'slug' => $document->slug,
                'type_slug' => Sections::typeSlug($document->kind),
            ])
            ->all();
    }

    /** Numeric value of a challenge rating string ("1/4" → 0.25, "5" → 5), or null when unparseable. */
    private function crValue(string $cr): ?float
    {
        $cr = trim($cr);
        if ($cr === '') {
            return null;
        }
        if (str_contains($cr, '/')) {
            [$numerator, $denominator] = array_pad(explode('/', $cr, 2), 2, '1');

            return is_numeric($numerator) && is_numeric($denominator) && (float) $denominator !== 0.0
                ? (float) $numerator / (float) $denominator
                : null;
        }

        return is_numeric($cr) ? (float) $cr : null;
    }
}
