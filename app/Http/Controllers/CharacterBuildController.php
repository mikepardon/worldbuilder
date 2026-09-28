<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\TalentAllocationException;
use App\Models\Campaign;
use App\Models\CampaignCompendiumItem;
use App\Models\Character;
use App\Models\CharacterBuild;
use App\Models\RuleSystem;
use App\Models\TalentNode;
use App\Models\World;
use App\Services\CharacterTotals;
use App\Services\TalentAllocator;
use App\Support\RuleSystemPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * A player building their character on the campaign's rule system. Every mutation runs through
 * {@see TalentAllocator}, so the server — not the browser — decides what is legal. A player edits
 * their own character; the campaign GM may edit any, and only the GM may adjust the point budget.
 */
class CharacterBuildController extends Controller
{
    public function __construct(
        private readonly TalentAllocator $allocator,
        private readonly CharacterTotals $totals,
    ) {}

    public function show(Request $request, World $world, Campaign $campaign, Character $character)
    {
        $this->guard($request, $character);
        $system = $campaign->ruleSystem;

        if ($system === null) {
            return Inertia::render('Campaigns/Characters/Build', [
                'system' => null,
                'character' => $this->characterPayload($character),
                'backHref' => route('campaigns.show', [$world, $campaign]),
            ]);
        }

        $build = $this->build($character, $system);

        return Inertia::render('Campaigns/Characters/Build', [
            'system' => RuleSystemPresenter::full($system),
            'build' => $this->buildPayload($build),
            'sheet' => $this->totals->sheet($build),
            'character' => $this->characterPayload($character),
            'race' => $this->racePayload($character),
            'compendium' => $this->worldCompendium((int) $world->id),
            'canManage' => $request->user()->can('manage', $campaign),
            'backHref' => route('campaigns.show', [$world, $campaign]),
        ]);
    }

    /**
     * The world's spells/feats/abilities, so the sheet can resolve the grant effects on allocated nodes.
     *
     * @return list<array<string, mixed>>
     */
    private function worldCompendium(int $worldId): array
    {
        return CampaignCompendiumItem::query()
            ->where('world_id', $worldId)
            ->whereIn('item_type', ['spell', 'feat', 'ability'])
            ->orderBy('name')
            ->get(['id', 'name', 'item_type', 'summary', 'fields', 'document'])
            ->map(fn (CampaignCompendiumItem $item): array => RuleSystemPresenter::compendiumRef($item))
            ->all();
    }

    /**
     * The character's chosen race for the build: its base modifiers (folded into the sheet client-side,
     * mirroring the server) and the spells/feats it grants, resolved from the world's compendium.
     *
     * @return array{id: int, name: string, effects: list<array<string, mixed>>, grants: list<array<string, mixed>>}|null
     */
    private function racePayload(Character $character): ?array
    {
        $race = $character->raceItem;
        if ($race === null) {
            return null;
        }

        $grantIds = collect(data_get($race->fields, 'grants', []))
            ->filter(fn ($id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn ($id): int => (int) $id)
            ->all();

        $items = $grantIds === []
            ? collect()
            : CampaignCompendiumItem::whereIn('id', $grantIds)
                ->where('world_id', $character->campaign->world_id)
                ->get();

        return [
            'id' => $race->id,
            'name' => $race->name,
            'effects' => array_values(array_filter(
                (array) data_get($race->fields, 'stat_modifiers', []),
                fn ($effect): bool => is_array($effect) && isset($effect['type'], $effect['key']),
            )),
            'grants' => $items->map(fn (CampaignCompendiumItem $item): array => RuleSystemPresenter::compendiumRef($item))->values()->all(),
        ];
    }

    public function allocate(Request $request, Character $character)
    {
        [$build] = $this->resolve($request, $character);

        $data = $request->validate([
            'node_id' => ['required', 'integer'],
            'option' => ['nullable', 'string', 'max:60'],
            'choices' => ['nullable', 'array'],
        ]);

        $node = $this->nodeInSystem($build->ruleSystem, (int) $data['node_id']);

        return $this->attempt(fn () => $this->allocator->allocate($build, $node, $data['option'] ?? null, (array) ($data['choices'] ?? [])));
    }

    public function chooseChoices(Request $request, Character $character)
    {
        [$build] = $this->resolve($request, $character);
        $data = $request->validate([
            'node_id' => ['required', 'integer'],
            'choices' => ['present', 'array'],
        ]);
        $node = $this->nodeInSystem($build->ruleSystem, (int) $data['node_id']);

        return $this->attempt(fn () => $this->allocator->chooseChoices($build, $node, (array) $data['choices']));
    }

    public function deallocate(Request $request, Character $character)
    {
        [$build] = $this->resolve($request, $character);
        $node = $this->nodeInSystem($build->ruleSystem, (int) $request->validate(['node_id' => ['required', 'integer']])['node_id']);

        return $this->attempt(fn () => $this->allocator->deallocate($build, $node));
    }

    public function enhance(Request $request, Character $character)
    {
        [$build] = $this->resolve($request, $character);
        $node = $this->nodeInSystem($build->ruleSystem, (int) $request->validate(['node_id' => ['required', 'integer']])['node_id']);

        return $this->attempt(fn () => $this->allocator->enhance($build, $node));
    }

    public function chooseOption(Request $request, Character $character)
    {
        [$build] = $this->resolve($request, $character);
        $data = $request->validate([
            'node_id' => ['required', 'integer'],
            'option' => ['required', 'string', 'max:60'],
        ]);
        $node = $this->nodeInSystem($build->ruleSystem, (int) $data['node_id']);

        return $this->attempt(fn () => $this->allocator->chooseOption($build, $node, $data['option']));
    }

    public function respec(Request $request, Character $character)
    {
        [$build] = $this->resolve($request, $character);

        $this->allocator->respec($build);

        return back();
    }

    public function settings(Request $request, Character $character)
    {
        $campaign = $character->campaign;
        $this->authorize('manage', $campaign);

        $system = $campaign->ruleSystem;
        abort_if($system === null, 409, 'This campaign has no rule system enabled.');

        $data = $request->validate([
            'manual_points' => ['required', 'integer', 'min:0', 'max:9999'],
            'level_override' => ['nullable', 'integer', 'min:1', 'max:100'],
            'xp' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->build($character, $system)->update($data);

        return back();
    }

    /**
     * Resolve the build for a mutation, guarding access and requiring an enabled system.
     *
     * @return array{0: CharacterBuild}
     */
    private function resolve(Request $request, Character $character): array
    {
        $this->guard($request, $character);
        $system = $character->campaign->ruleSystem;
        abort_if($system === null, 409, 'This campaign has no rule system enabled.');

        return [$this->build($character, $system)];
    }

    private function guard(Request $request, Character $character): void
    {
        $isOwner = $character->user_id !== null && $character->user_id === $request->user()->id;

        abort_unless($isOwner || $request->user()->can('manage', $character->campaign), 403);
    }

    private function build(Character $character, RuleSystem $system): CharacterBuild
    {
        $build = $character->builds()->firstOrCreate(
            ['rule_system_id' => $system->id],
            ['manual_points' => 0],
        );

        $this->allocator->ensureRootsAllocated($build);

        return $build->load(['talents.node', 'ruleSystem.stats', 'ruleSystem.resources', 'ruleSystem.skills', 'ruleSystem.levels']);
    }

    private function nodeInSystem(RuleSystem $system, int $nodeId): TalentNode
    {
        $webIds = $system->webs()->pluck('id');

        return TalentNode::whereIn('talent_web_id', $webIds)->findOrFail($nodeId);
    }

    /**
     * Run a mutation and turn a rules violation into a clean validation error for the page.
     */
    private function attempt(callable $mutation)
    {
        try {
            $mutation();
        } catch (TalentAllocationException $exception) {
            return back()->withErrors(['talent' => $exception->getMessage()]);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(CharacterBuild $build): array
    {
        return [
            'id' => $build->id,
            'manual_points' => $build->manual_points,
            'level_override' => $build->level_override,
            'xp' => $build->xp,
            'talents' => $build->talents->map(fn ($talent) => [
                'node_id' => $talent->talent_node_id,
                'chosen_option' => $talent->chosen_option,
                'choices' => $talent->choices ?? [],
                'rank' => $talent->rank,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function characterPayload(Character $character): array
    {
        return [
            'id' => $character->id,
            'name' => $character->name,
            'slug' => $character->slug,
            'level' => $character->level,
        ];
    }
}
