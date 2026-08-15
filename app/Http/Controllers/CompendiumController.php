<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CampaignCompendiumItem;
use App\Models\CompendiumItem;
use App\Models\World;
use App\Services\AnthropicClient;
use App\Services\CompendiumDrafter;
use App\Services\CritterDbClient;
use App\Services\Open5eClient;
use App\Support\AiUsageContext;
use App\Support\Compendium;
use App\Support\CompendiumFields;
use App\Support\CritterDb;
use App\Support\Statblock;
use App\Support\WorldNav;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;
use Throwable;

class CompendiumController extends Controller
{
    public function index(World $world)
    {
        $this->authorize('editContent', $world);

        $items = $world->compendiumItems()->with('image')->orderBy('name')->get();

        return Inertia::render('Compendium/Index', [
            'world' => WorldNav::for($world),
            'campaign' => ['id' => $world->id, 'name' => $world->name, 'ddb_enabled' => $world->ddb_enabled, 'ddb_saved' => filled($world->ddbCobalt())],
            'types' => Compendium::withCounts($items),
            'items' => $items->map(fn (CampaignCompendiumItem $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'item_type' => $i->item_type,
                'typeLabel' => Compendium::label($i->item_type),
                'summary' => $i->summary,
                'provider' => $i->provider,
                'source' => Compendium::sourceLabel($i->origin, $i->provider),
                'locked' => $i->isLocked(),
                'is_private' => $i->is_private,
                'is_active' => $i->is_active,
                'tags' => $i->tags ?? [],
                'image_url' => $i->image?->url,
            ]),
            'allTags' => $this->allTags($items),
            'typeOptions' => collect(Compendium::keys())
                ->map(fn ($k) => ['value' => $k, 'label' => Compendium::label($k)]),
        ]);
    }

    /** Distinct, sorted tags used across a world's compendium (for filters + autocomplete). */
    protected function allTags($items): array
    {
        return collect($items)->pluck('tags')->flatten()->filter()->unique()->sort()->values()->all();
    }

    public function store(Request $request, World $world)
    {
        $this->authorize('editContent', $world);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'item_type' => ['required', 'in:'.implode(',', Compendium::keys())],
        ]);

        $item = $world->compendiumItems()->create([
            'user_id' => $request->user()->id,
            'item_type' => $data['item_type'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'name' => $data['name'],
            'provider' => 'custom',
            'fields' => $data['item_type'] === 'monster' ? ['block' => Statblock::empty()] : [],
            // Monsters seed a blank stat block; other types start empty and are filled via the
            // structured field editor, which regenerates the document on save.
            'document' => $data['item_type'] === 'monster'
                ? Statblock::toMarkdown(Statblock::empty(), $data['name'])
                : '',
        ]);

        return redirect()->route('compendium.edit', $item);
    }

    /** Browse the global, admin-curated library so the GM can pick entries to copy into this world. */
    public function browse(Request $request, World $world)
    {
        $this->authorize('editContent', $world);

        $data = $request->validate([
            'item_type' => ['required', 'in:'.implode(',', Compendium::keys())],
            'query' => ['nullable', 'string', 'max:120'],
        ]);

        $taken = $world->compendiumItems()->where('item_type', $data['item_type'])->pluck('slug')->all();

        $results = CompendiumItem::query()
            ->where('item_type', $data['item_type'])
            ->where('visible', true)
            ->when($data['query'] ?? null, fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn (CompendiumItem $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'slug' => $i->slug,
                'summary' => $i->summary,
                'meta' => Open5eClient::preview($i->item_type, $i->data ?? [])['meta'],
                'exists' => in_array($i->slug, $taken, true),
            ])
            ->values();

        return response()->json(['results' => $results]);
    }

    /** Copy hand-picked global-library entries into this world's compendium (locked, read-only copies). */
    public function import(Request $request, World $world)
    {
        $this->authorize('editContent', $world);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $sources = CompendiumItem::query()->whereIn('id', $data['ids'])->with('source')->get();
        $imported = $refreshed = 0;

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

            if ($existing) {
                // Re-importing refreshes an existing imported copy from the (possibly updated) library,
                // but never clobbers an editable custom/cloned entry the GM has made.
                if ($existing->provider === 'imported') {
                    $existing->update($content);
                    $refreshed++;
                }

                continue;
            }

            $world->compendiumItems()->create([
                'user_id' => $request->user()->id,
                'item_type' => $source->item_type,
                'slug' => $source->slug,
                ...$content,
                'provider' => 'imported',
                'is_private' => false,
                'is_active' => true,
            ]);
            $imported++;
        }

        $message = "{$imported} ".Str::plural('entry', $imported).' imported from the library.';
        if ($refreshed > 0) {
            $message .= " {$refreshed} refreshed.";
        }

        return back()->with('success', $message);
    }

    /** Duplicate an existing entry into a fresh, fully-editable custom copy the GM can mod. */
    public function clone(Request $request, CampaignCompendiumItem $item)
    {
        $this->authorize('update', $item);

        $copy = $item->world->compendiumItems()->create([
            'user_id' => $request->user()->id,
            'item_type' => $item->item_type,
            'slug' => Str::slug($item->name).'-copy-'.Str::lower(Str::random(4)),
            'name' => $item->name.' (copy)',
            'summary' => $item->summary,
            'document' => $item->document,
            'fields' => $item->fields,
            'data' => $item->data,
            'tags' => $item->tags,
            'provider' => 'custom', // a clone is unlocked and editable, regardless of the source
            'origin' => $item->origin, // keep the provenance (D&D Beyond, Open5e, …)
            'is_private' => $item->is_private,
            'is_active' => true,
        ]);

        return redirect()->route('compendium.edit', $copy);
    }

    /**
     * Import monsters from CritterDB — either a pasted JSON export or a published-bestiary URL we
     * fetch over CritterDB's API. Each creature becomes an editable custom monster (no upstream to
     * sync), GM-only until revealed. See {@see CritterDb} for the stat-block mapping.
     */
    public function critterDb(Request $request, World $world, CritterDbClient $client)
    {
        $this->authorize('editContent', $world);

        $data = $request->validate([
            'mode' => ['required', 'in:paste,url'],
            'json' => ['required_if:mode,paste', 'nullable', 'string', 'max:2000000'],
            'url' => ['required_if:mode,url', 'nullable', 'string', 'max:2048'],
        ]);

        $creatures = $data['mode'] === 'url'
            ? $this->critterDbFromUrl($client, $data['url'])
            : $this->critterDbFromJson($data['json']);

        if ($creatures === []) {
            throw ValidationException::withMessages([
                $data['mode'] => 'No creatures found. Check the '.($data['mode'] === 'url' ? 'bestiary link' : 'pasted JSON').' and try again.',
            ]);
        }

        $imported = DB::transaction(function () use ($creatures, $world, $request): int {
            foreach ($creatures as $creature) {
                $mapped = CritterDb::toItem($creature);
                $world->compendiumItems()->create([
                    'user_id' => $request->user()->id,
                    'item_type' => 'monster',
                    'slug' => Str::slug($mapped['name']).'-'.Str::lower(Str::random(4)),
                    'name' => $mapped['name'],
                    'summary' => $mapped['summary'],
                    'document' => $mapped['document'],
                    'fields' => ['block' => $mapped['block']],
                    'provider' => 'custom', // editable homebrew — there is no upstream source to sync to
                    'origin' => 'critterdb',
                    'is_private' => true,   // GM-only until the GM chooses to reveal it to players
                    'is_active' => true,
                ]);
            }

            return count($creatures);
        });

        return redirect()->route('compendium.index', $world)
            ->with('success', "{$imported} ".Str::plural('monster', $imported).' imported from CritterDB (GM-only until you reveal them).');
    }

    /** @return list<array<string, mixed>> */
    private function critterDbFromJson(?string $json): array
    {
        $decoded = json_decode((string) $json, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['json' => 'That does not look like valid CritterDB JSON.']);
        }

        return CritterDb::creaturesFromExport($decoded);
    }

    /** @return list<array<string, mixed>> */
    private function critterDbFromUrl(CritterDbClient $client, ?string $url): array
    {
        $bestiaryId = CritterDb::bestiaryIdFromReference((string) $url);
        if ($bestiaryId === null) {
            throw ValidationException::withMessages(['url' => 'Paste a CritterDB published-bestiary link — it contains a long id.']);
        }

        try {
            return $client->creatures($bestiaryId);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }
    }

    public function edit(CampaignCompendiumItem $item)
    {
        $this->authorize('view', $item);

        $world = $item->world;

        return Inertia::render('Compendium/Edit', [
            'campaign' => ['id' => $item->world_id, 'name' => $world->name],
            'item' => [
                'id' => $item->id,
                'name' => $item->name,
                'item_type' => $item->item_type,
                'typeLabel' => Compendium::label($item->item_type),
                'summary' => $item->summary,
                'document' => $item->document,
                'is_private' => $item->is_private,
                'is_active' => $item->is_active,
                'tags' => $item->tags ?? [],
                'provider' => $item->provider,
                'locked' => $item->isLocked(),
                'block' => data_get($item->fields, 'block'),
                // Structured field values for non-monster types (spell/weapon/armor/…).
                'fields' => $item->item_type === 'monster' ? [] : ($item->fields ?? []),
                'hasSource' => filled($item->data),
                'embedToken' => '['.($item->item_type === 'monster' ? 'statblock' : $item->item_type).'='.$item->id.']',
                'image_media_id' => $item->image_media_id,
                'image_url' => $item->image?->url,
            ],
            // Field schema driving the structured editor for non-monster types (empty for monsters).
            'fieldSchema' => CompendiumFields::for($item->item_type),
            'allTags' => $this->allTags($world->compendiumItems()->get()),
            // Custom races defined in this world's compendium — offered in the monster Type dropdown.
            'races' => $world->compendiumItems()
                ->where('item_type', 'race')
                ->orderBy('name')
                ->pluck('name')
                ->all(),
            // Spells in this world's compendium — picked into a monster's Spellcasting block (and
            // hover-previewed by their summary in the stat block).
            'spells' => $world->compendiumItems()
                ->where('item_type', 'spell')
                ->orderBy('name')
                ->get(['id', 'name', 'summary', 'document', 'fields'])
                ->map(fn ($spell) => ['id' => $spell->id, 'name' => $spell->name, 'summary' => $spell->summary, 'document' => $spell->document, 'fields' => $spell->fields ?? []])
                ->all(),
            'ai' => [
                'configured' => app(AnthropicClient::class)->configured(),
                'remaining' => $world->aiGenerationsRemaining(),
                'limit' => $world->ai_generation_limit,
            ],
        ]);
    }

    public function update(Request $request, CampaignCompendiumItem $item)
    {
        $this->authorize('update', $item);

        // Imported entries are read-only copies of the global library: only their world-local
        // metadata (player visibility + tags) may change here. Use "Clone" to make an editable copy.
        if ($item->isLocked()) {
            $data = $request->validate([
                'is_active' => ['sometimes', 'boolean'],
                'tags' => ['nullable', 'array'],
                'tags.*' => ['string', 'max:40'],
            ]);
            $item->fill($data)->save();

            return back();
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'string'],
            'is_private' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
            'block' => ['nullable', 'array'],
            'fields' => ['nullable', 'array'],
            // Per-world image override: repoint at a world-owned upload, or clear to fall back to none.
            'image_media_id' => ['nullable', 'integer', 'exists:media,id'],
        ]);

        // For monsters, the structured block is the source of truth — regenerate the markdown.
        if ($item->item_type === 'monster' && isset($data['block'])) {
            $fields = $item->fields ?? [];
            $fields['block'] = $data['block'];
            $item->fields = $fields;
            $data['document'] = Statblock::toMarkdown($data['block'], $data['name'] ?? $item->name);
        } elseif (CompendiumFields::has($item->item_type) && isset($data['fields'])) {
            // Other types edit structured fields; regenerate the document from them.
            $item->fields = $data['fields'];
            $data['document'] = CompendiumFields::toMarkdown($item->item_type, $data['fields'], $data['name'] ?? $item->name);
        }
        unset($data['block'], $data['fields']);

        $item->fill($data)->save();

        return back();
    }

    /**
     * Structured Claude draft for a compendium entry: fills the monster stat block (or the Markdown
     * document for other types) plus the summary, so the editor can apply it directly. Counts against
     * the world's AI budget, exactly like ai() above.
     */
    public function draft(Request $request, CampaignCompendiumItem $item, CompendiumDrafter $drafter)
    {
        $this->authorize('update', $item);
        abort_if($item->isLocked(), 403, 'Imported entries are read-only. Clone it to make an editable copy.');

        $input = $request->validate([
            'prompt' => ['required', 'string', 'max:8000'],
            'document' => ['nullable', 'string'],
            'history' => ['nullable', 'array', 'max:40'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
        ]);

        if (! $drafter->configured()) {
            return response()->json([
                'message' => "AI isn't set up on this server yet.",
            ], 422);
        }

        $world = $item->world;
        if (! $world->canUseAi()) {
            return response()->json([
                'message' => $world->ai_generation_limit > 0
                    ? 'This world has used all of its AI generations. Ask an admin to top it up.'
                    : "AI generation isn't enabled for this world yet. Ask an admin to grant a budget.",
            ], 422);
        }

        $currentBlock = (array) (data_get($item->fields, 'block') ?? Statblock::empty());
        $currentFields = (array) ($item->fields ?? []);
        $document = $input['document'] ?? $item->document ?? '';

        try {
            $result = $drafter->draft(
                $item->item_type, $item->name, $world->name, $currentBlock, $currentFields, $document, $input['prompt'], $input['history'] ?? [],
                new AiUsageContext('compendium_draft', $world->id, $request->user()->id, $item->item_type),
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result === null) {
            return response()->json(['message' => 'The AI returned an unexpected response. Please try again.'], 422);
        }

        // Only a successful generation is billed against the world's budget.
        $world->increment('ai_generations_used');

        return response()->json([
            ...$result,
            'ai' => [
                'remaining' => $world->fresh()->aiGenerationsRemaining(),
                'limit' => $world->ai_generation_limit,
            ],
        ]);
    }

    /** Rebuild a monster's stat block + markdown from its preserved import source (Open5e). */
    public function rebuild(CampaignCompendiumItem $item)
    {
        $this->authorize('update', $item);
        abort_unless($item->item_type === 'monster', 400, 'Only monsters have stat blocks.');

        $block = Statblock::fromOpen5e($item->data);
        abort_if(! $block, 422, 'No source data to rebuild from.');

        $fields = $item->fields ?? [];
        $fields['block'] = $block;
        $item->update([
            'fields' => $fields,
            'document' => Statblock::toMarkdown($block, $item->name),
        ]);

        return back();
    }

    public function destroy(CampaignCompendiumItem $item)
    {
        $this->authorize('delete', $item);
        $world = $item->world;
        $item->delete();

        return redirect()->route('compendium.index', $world);
    }
}
