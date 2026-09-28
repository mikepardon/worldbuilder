<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\NodeShape;
use App\Enums\ProgressionMode;
use App\Models\CampaignCompendiumItem;
use App\Models\CompendiumItem;
use App\Models\CompendiumSource;
use App\Models\RuleSystem;
use App\Policies\RuleSystemPolicy;
use App\Services\WorldCompendiumImporter;
use App\Support\RuleSystemPresenter;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The shared talent-system builder, used for both global templates (admins, via Gate::before) and a
 * world's own systems (its co-authors). {@see RuleSystemPolicy} decides who may edit.
 * The webs, nodes and edges are managed incrementally by their own controllers; this one owns the
 * system's meta, settings, and the small list-editable tables (stats, resources, skills, levels, kinds).
 */
class RuleSystemController extends Controller
{
    public function builder(RuleSystem $ruleSystem)
    {
        $this->authorize('update', $ruleSystem);

        return Inertia::render('RuleSystems/Builder', [
            'system' => RuleSystemPresenter::full($ruleSystem),
            'context' => $ruleSystem->is_template ? 'admin' : 'world',
            'backHref' => $ruleSystem->is_template
                ? route('admin.rule-systems.index')
                : route('rule-systems.index', $ruleSystem->world_id),
            // Spells/feats/abilities a node can grant. For a world system these are the world's own
            // compendium; for a template they come from its prerequisite library sources.
            'compendium' => $this->grantCompendium($ruleSystem),
            // The global library "compendiums" (sources) that can be marked as prerequisites of this system.
            'compendiumSources' => $this->availableSources(),
            // Races a preview character can pick (world compendium, or a template's prerequisite races).
            'races' => $this->raceOptions($ruleSystem),
            'options' => [
                'progressionModes' => array_map(
                    fn (ProgressionMode $mode) => ['value' => $mode->value, 'label' => $mode->label()],
                    ProgressionMode::cases(),
                ),
                'shapes' => array_map(
                    fn (NodeShape $shape) => ['value' => $shape->value, 'label' => $shape->label()],
                    NodeShape::cases(),
                ),
            ],
        ]);
    }

    /**
     * The spells/feats/abilities a node in this system can grant. A world system grants from its own
     * compendium; a template grants from the entries of its prerequisite library sources (which get
     * imported into a world when the template is adopted).
     *
     * @return list<array{id: int, name: string, item_type: string, summary: string|null, fields: array<string, mixed>, document: string|null}>
     */
    private function grantCompendium(RuleSystem $ruleSystem): array
    {
        $types = ['spell', 'feat', 'ability'];
        $columns = ['id', 'name', 'item_type', 'summary', 'fields', 'document'];

        $items = $ruleSystem->world_id !== null
            ? CampaignCompendiumItem::query()->where('world_id', $ruleSystem->world_id)->whereIn('item_type', $types)->orderBy('name')->get($columns)
            : ($this->prerequisiteSourceIds($ruleSystem) === []
                ? collect()
                : CompendiumItem::query()->whereIn('source_id', $this->prerequisiteSourceIds($ruleSystem))->whereIn('item_type', $types)->orderBy('name')->get($columns));

        return $items->map(fn ($item): array => [
            'id' => $item->id,
            'name' => $item->name,
            'item_type' => $item->item_type,
            'summary' => $item->summary,
            'fields' => $item->fields ?? [],
            'document' => $item->document,
        ])->all();
    }

    /**
     * The races a preview character can pick: the world's own for a world system, or the template's
     * prerequisite races. Each carries its base modifiers and any granted entries, so the preview folds
     * them in exactly as a real character would.
     *
     * @return list<array<string, mixed>>
     */
    private function raceOptions(RuleSystem $ruleSystem): array
    {
        if ($ruleSystem->world_id !== null) {
            $items = CampaignCompendiumItem::query()->where('world_id', $ruleSystem->world_id)->where('item_type', 'race')->orderBy('name')->get();
            $resolve = fn (array $ids) => $ids === [] ? collect() : CampaignCompendiumItem::whereIn('id', $ids)->where('world_id', $ruleSystem->world_id)->get();
        } else {
            $sourceIds = $this->prerequisiteSourceIds($ruleSystem);
            if ($sourceIds === []) {
                return [];
            }
            $items = CompendiumItem::query()->whereIn('source_id', $sourceIds)->where('item_type', 'race')->orderBy('name')->get();
            $resolve = fn (array $ids) => $ids === [] ? collect() : CompendiumItem::whereIn('id', $ids)->get();
        }

        return $items->map(function ($race) use ($resolve): array {
            $grantIds = collect(data_get($race->fields, 'grants', []))
                ->filter(fn ($id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
                ->map(fn ($id): int => (int) $id)
                ->all();

            return [
                'id' => $race->id,
                'name' => $race->name,
                'effects' => array_values(array_filter(
                    (array) data_get($race->fields, 'stat_modifiers', []),
                    fn ($effect): bool => is_array($effect) && isset($effect['type'], $effect['key']),
                )),
                'grants' => $resolve($grantIds)->map(fn ($item): array => [
                    'id' => $item->id, 'name' => $item->name, 'item_type' => $item->item_type,
                    'summary' => $item->summary, 'fields' => $item->fields ?? [], 'document' => $item->document,
                ])->values()->all(),
            ];
        })->all();
    }

    /** The enabled global library sources, with entry counts, offered as prerequisite "compendiums". */
    private function availableSources(): array
    {
        return CompendiumSource::query()
            ->where('enabled', true)
            ->withCount('items')
            ->orderBy('item_type')
            ->orderBy('name')
            ->get()
            ->map(fn (CompendiumSource $source): array => [
                'id' => $source->id,
                'name' => $source->name,
                'item_type' => $source->item_type,
                'count' => $source->items_count,
            ])
            ->all();
    }

    /**
     * The library source ids marked as prerequisites of this system.
     *
     * @return list<int>
     */
    private function prerequisiteSourceIds(RuleSystem $ruleSystem): array
    {
        return collect(data_get($ruleSystem->settings, 'compendium_sources', []))
            ->filter(fn ($id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /** Pull this world system's prerequisite compendiums into its world compendium now. */
    public function importCompendium(Request $request, RuleSystem $ruleSystem, WorldCompendiumImporter $importer)
    {
        $this->authorize('update', $ruleSystem);
        abort_if($ruleSystem->world_id === null, 422, 'A template has no world to import into.');

        $sourceIds = $this->prerequisiteSourceIds($ruleSystem);
        if ($sourceIds === []) {
            return back()->with('success', 'Select prerequisite compendiums first.');
        }

        $result = $importer->importSources($ruleSystem->world, $sourceIds, $request->user()->id);
        $message = "{$result['imported']} ".\Illuminate\Support\Str::plural('entry', $result['imported']).' imported from the library.';
        if ($result['refreshed'] > 0) {
            $message .= " {$result['refreshed']} refreshed.";
        }

        return back()->with('success', $message);
    }

    public function update(Request $request, RuleSystem $ruleSystem)
    {
        $this->authorize('update', $ruleSystem);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'settings' => ['sometimes', 'array'],
            'settings.progression_mode' => ['sometimes', Rule::enum(ProgressionMode::class)],
            'settings.points_cumulative' => ['sometimes', 'boolean'],
            'settings.starting_points' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'settings.level_cap' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'settings.role_kinds' => ['sometimes', 'array'],
            'settings.role_kinds.child' => ['sometimes', 'nullable', 'string', 'max:40'],
            'settings.role_kinds.adjacent' => ['sometimes', 'nullable', 'string', 'max:40'],
            'settings.role_kinds.master' => ['sometimes', 'nullable', 'string', 'max:40'],
            'settings.compendium_sources' => ['sometimes', 'array'],
            'settings.compendium_sources.*' => ['integer', Rule::exists('compendium_sources', 'id')],

            'stats' => ['sometimes', 'array'],
            'stats.*.id' => ['nullable', 'integer'],
            'stats.*.key' => ['required', 'string', 'max:40'],
            'stats.*.label' => ['required', 'string', 'max:80'],
            'stats.*.abbreviation' => ['nullable', 'string', 'max:10'],
            'stats.*.description' => ['nullable', 'string', 'max:500'],
            'stats.*.default_value' => ['required', 'integer', 'min:-99', 'max:999'],

            'resources' => ['sometimes', 'array'],
            'resources.*.id' => ['nullable', 'integer'],
            'resources.*.key' => ['required', 'string', 'max:40'],
            'resources.*.label' => ['required', 'string', 'max:80'],
            'resources.*.description' => ['nullable', 'string', 'max:500'],
            'resources.*.base_value' => ['required', 'integer', 'min:-999', 'max:9999'],
            'resources.*.colour' => ['nullable', 'string', 'max:20'],

            'skills' => ['sometimes', 'array'],
            'skills.*.id' => ['nullable', 'integer'],
            'skills.*.key' => ['required', 'string', 'max:60'],
            'skills.*.label' => ['required', 'string', 'max:80'],
            'skills.*.governing_stat_key' => ['nullable', 'string', 'max:40'],
            'skills.*.description' => ['nullable', 'string', 'max:500'],

            'levels' => ['sometimes', 'array'],
            'levels.*.id' => ['nullable', 'integer'],
            'levels.*.level' => ['required', 'integer', 'min:1', 'max:100'],
            'levels.*.xp_required' => ['nullable', 'integer', 'min:0'],
            'levels.*.talent_points' => ['required', 'integer', 'min:0', 'max:999'],
            'levels.*.notes' => ['nullable', 'string', 'max:200'],

            'nodeKinds' => ['sometimes', 'array'],
            'nodeKinds.*.id' => ['nullable', 'integer'],
            'nodeKinds.*.key' => ['required', 'string', 'max:40'],
            'nodeKinds.*.label' => ['required', 'string', 'max:80'],
            'nodeKinds.*.default_cost' => ['required', 'integer', 'min:0', 'max:99'],
            'nodeKinds.*.shape' => ['required', Rule::enum(NodeShape::class)],
            'nodeKinds.*.glyph' => ['nullable', 'string', 'max:8'],
            'nodeKinds.*.size' => ['required', 'integer', 'min:12', 'max:120'],
            'nodeKinds.*.colour' => ['nullable', 'string', 'max:20'],
            'nodeKinds.*.max_per_character' => ['nullable', 'integer', 'min:0', 'max:99'],
        ]);

        DB::transaction(function () use ($ruleSystem, $data): void {
            $ruleSystem->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'settings' => array_merge((array) $ruleSystem->settings, $this->settingsFrom($data)),
            ]);

            if (array_key_exists('stats', $data)) {
                $this->reconcile($ruleSystem->stats(), $this->rows($data['stats'], ['key', 'label', 'abbreviation', 'description', 'default_value']));
            }
            if (array_key_exists('resources', $data)) {
                $this->reconcile($ruleSystem->resources(), $this->rows($data['resources'], ['key', 'label', 'description', 'base_value', 'colour']));
            }
            if (array_key_exists('skills', $data)) {
                $this->reconcile($ruleSystem->skills(), $this->rows($data['skills'], ['key', 'label', 'governing_stat_key', 'description']));
            }
            if (array_key_exists('levels', $data)) {
                $this->reconcile($ruleSystem->levels(), $this->rows($data['levels'], ['level', 'xp_required', 'talent_points', 'notes']));
            }
            if (array_key_exists('nodeKinds', $data)) {
                $this->reconcile($ruleSystem->nodeKinds(), $this->rows($data['nodeKinds'], ['key', 'label', 'default_cost', 'shape', 'glyph', 'size', 'colour', 'max_per_character']));
            }
        });

        return back();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function settingsFrom(array $data): array
    {
        $settings = $data['settings'] ?? [];
        $allowed = ['progression_mode', 'points_cumulative', 'starting_points', 'level_cap', 'role_kinds', 'compendium_sources'];

        return array_intersect_key($settings, array_flip($allowed));
    }

    /**
     * Map validated list rows down to exactly the columns we persist, preserving sort order and any id.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    private function rows(array $items, array $columns): array
    {
        $rows = [];
        foreach (array_values($items) as $index => $item) {
            $row = ['id' => $item['id'] ?? null, 'sort' => $index];
            foreach ($columns as $column) {
                $row[$column] = $item[$column] ?? null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Upsert the given rows onto a relation and delete any of its records not present in the set.
     *
     * @param  HasMany<covariant \Illuminate\Database\Eloquent\Model, RuleSystem>  $relation
     * @param  list<array<string, mixed>>  $rows
     */
    private function reconcile(HasMany $relation, array $rows): void
    {
        $existing = $relation->get()->keyBy('id');
        $keep = [];

        foreach ($rows as $row) {
            $id = $row['id'];
            unset($row['id']);

            if ($id !== null && $existing->has($id)) {
                $existing->get($id)->update($row);
                $keep[] = $id;

                continue;
            }

            $keep[] = $relation->create($row)->id;
        }

        foreach ($existing as $id => $model) {
            if (! in_array($id, $keep, true)) {
                $model->delete();
            }
        }
    }
}
