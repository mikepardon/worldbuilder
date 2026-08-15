<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CampaignCompendiumItem;
use App\Models\World;
use App\Queries\DocumentQuery;
use App\Support\Compendium;
use App\Support\Sections;
use App\Support\Viewer;
use App\Support\WorldNav;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** World-wide search across entries and the compendium, for the GM workspace. */
class SearchController extends Controller
{
    public function __construct(private readonly DocumentQuery $documents) {}

    public function index(Request $request, World $world)
    {
        $this->authorize('manage', $world);

        $term = trim((string) $request->query('q', ''));
        $viewer = Viewer::for($world, $request->user());

        $entries = $term === ''
            ? collect()
            : $this->documents->search($world, $viewer, $term)->map(fn ($entry) => $entry->toManageRow());

        $items = $term === ''
            ? collect()
            : $world->compendiumItems()->visibleTo($viewer)->search($term)->orderBy('name')->limit(100)->get()
                ->map(fn (CampaignCompendiumItem $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'item_type' => $item->item_type,
                    'typeLabel' => Compendium::label($item->item_type),
                    'summary' => $item->summary,
                    'source' => Compendium::sourceLabel($item->origin, $item->provider),
                ]);

        return Inertia::render('Search/Index', [
            'world' => WorldNav::for($world),
            'campaign' => ['id' => $world->id, 'name' => $world->name],
            'q' => $term,
            'entries' => $entries->values(),
            'items' => $items->values(),
        ]);
    }

    /** Live search for the workspace modal: entries + compendium, grouped by kind, as JSON. */
    public function query(Request $request, World $world): JsonResponse
    {
        $this->authorize('manage', $world);

        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['groups' => []]);
        }

        $viewer = Viewer::for($world, $request->user());

        /** @var array<string, list<array{title: string, sub: string, url: string}>> $groups */
        $groups = [];

        foreach ($this->documents->search($world, $viewer, $term)->take(30) as $entry) {
            $row = $entry->toManageRow();
            $section = Sections::sectionForKind($row['kind']);
            $label = $section['label'] ?? $row['kindLabel'];
            $groups[$label][] = [
                'title' => $row['title'],
                'sub' => $row['kindLabel'],
                'url' => route('documents.edit', $row['id']),
            ];
        }

        foreach ($world->compendiumItems()->visibleTo($viewer)->search($term)->orderBy('name')->limit(15)->get() as $item) {
            $groups['Compendium'][] = [
                'title' => $item->name,
                'sub' => Compendium::label($item->item_type),
                'url' => route('compendium.edit', $item->id),
            ];
        }

        return response()->json([
            'groups' => collect($groups)->map(fn (array $items, string $label): array => [
                'label' => $label,
                'items' => $items,
            ])->values()->all(),
        ]);
    }
}
