<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunCompendiumImport;
use App\Models\CompendiumImportRun;
use App\Models\CompendiumItem;
use App\Models\CompendiumSource;
use App\Services\AnthropicClient;
use App\Services\CompendiumDrafter;
use App\Services\Open5eClient;
use App\Support\AiUsageContext;
use App\Support\Compendium;
use App\Support\CompendiumFields;
use App\Support\Statblock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Throwable;

class CompendiumController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Compendium/Index', [
            'total' => CompendiumItem::count(),
            'sources' => CompendiumSource::withCount('items')->orderBy('name')->get()
                ->map(fn (CompendiumSource $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'item_type' => $s->item_type,
                    'provider' => $s->provider,
                    'items_count' => $s->items_count,
                    'enabled' => $s->enabled,
                    'last_run_at' => $s->last_run_at?->toIso8601String(),
                ]),
            'runs' => CompendiumImportRun::with('source:id,name')->latest()->take(25)->get()
                ->map(fn (CompendiumImportRun $r) => [
                    'id' => $r->id,
                    'source' => $r->source?->name,
                    'status' => $r->status,
                    'added' => $r->added,
                    'updated' => $r->updated,
                    'unchanged' => $r->unchanged,
                    'error' => $r->error,
                    'finished_at' => $r->finished_at?->toIso8601String(),
                    'created_at' => $r->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /** Pull the source's SRD listing into the global library (synchronous, paginated). */
    public function import(CompendiumSource $source)
    {
        // Self-heal: an earlier synchronous import that timed out mid-fetch leaves a run stuck at
        // "running". Fail off any stale run for this source before starting a fresh one.
        $source->runs()
            ->whereIn('status', ['queued', 'running'])
            ->where('started_at', '<', now()->subMinutes(15))
            ->update(['status' => 'failed', 'error' => 'Timed out — superseded by a new import.', 'finished_at' => now()]);

        if ($source->runs()->whereIn('status', ['queued', 'running'])->exists()) {
            return back()->with('error', "{$source->name} is already importing — refresh to watch its progress.");
        }

        // The import now runs in the background as a chain of short jobs (see RunCompendiumImport).
        $run = $source->runs()->create(['status' => 'queued', 'started_at' => now()]);
        dispatch(new RunCompendiumImport($run));

        return back()->with('success', "{$source->name}: import started — it’ll fill in as it runs, so refresh to watch its progress.");
    }

    public function show(CompendiumSource $source)
    {
        return Inertia::render('Admin/Compendium/Show', [
            'source' => [
                'id' => $source->id,
                'name' => $source->name,
                'item_type' => $source->item_type,
                'typeLabel' => Compendium::label($source->item_type),
            ],
            'items' => $source->items()->orderBy('name')->get()
                ->map(fn (CompendiumItem $i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'slug' => $i->slug,
                    'summary' => $i->summary,
                    'meta' => Open5eClient::preview($source->item_type, $i->data ?? [])['meta'],
                    'version' => $i->version,
                ]),
        ]);
    }

    /** Full edit page for a single global library item (mirrors the world item editor). */
    public function edit(CompendiumItem $item)
    {
        return Inertia::render('Admin/Compendium/Edit', [
            'item' => [
                'id' => $item->id,
                'name' => $item->name,
                'item_type' => $item->item_type,
                'typeLabel' => Compendium::label($item->item_type),
                'source' => $item->source?->only(['id', 'name']),
                'summary' => $item->summary,
                'document' => $item->document,
                'block' => data_get($item->fields, 'block'),
                'fields' => $item->item_type === 'monster' ? [] : ($item->fields ?? []),
                'visible' => $item->visible,
                'hasSource' => ! empty($item->data),
                'version' => $item->version,
            ],
            'fieldSchema' => CompendiumFields::for($item->item_type),
            'races' => CompendiumItem::where('item_type', 'race')->where('visible', true)->orderBy('name')->pluck('name')->all(),
            'spells' => CompendiumItem::where('item_type', 'spell')->where('visible', true)->orderBy('name')
                ->get(['id', 'name', 'summary', 'document', 'fields'])
                ->map(fn (CompendiumItem $s) => ['id' => $s->id, 'name' => $s->name, 'summary' => $s->summary, 'document' => $s->document, 'fields' => $s->fields ?? []])
                ->all(),
            // Admin AI is unmetered (no world budget), so only whether it's configured matters.
            'ai' => ['configured' => app(AnthropicClient::class)->configured()],
        ]);
    }

    public function update(Request $request, CompendiumItem $item)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'string'],
            'block' => ['nullable', 'array'],
            'fields' => ['nullable', 'array'],
            'visible' => ['sometimes', 'boolean'],
        ]);

        // Monsters build from the structured block; other types from their CompendiumFields schema.
        if ($item->item_type === 'monster' && isset($data['block'])) {
            $fields = $item->fields ?? [];
            $fields['block'] = $data['block'];
            $item->fields = $fields;
            $data['document'] = Statblock::toMarkdown($data['block'], $data['name'] ?? $item->name);
        } elseif (CompendiumFields::has($item->item_type) && isset($data['fields'])) {
            $item->fields = $data['fields'];
            $data['document'] = CompendiumFields::toMarkdown($item->item_type, $data['fields'], $data['name'] ?? $item->name);
        }
        unset($data['block'], $data['fields']);

        // Editing bumps the version so world copies can tell they diverged from the source.
        $item->fill($data)->fill(['version' => $item->version + 1])->save();

        return back();
    }

    /** Unmetered Claude draft for a library entry (admins have no world budget to charge). */
    public function draft(Request $request, CompendiumItem $item, CompendiumDrafter $drafter)
    {
        $input = $request->validate([
            'prompt' => ['required', 'string', 'max:8000'],
            'document' => ['nullable', 'string'],
            'history' => ['nullable', 'array', 'max:40'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
        ]);

        if (! $drafter->configured()) {
            return response()->json(['message' => "AI isn't set up on this server yet."], 422);
        }

        $currentBlock = (array) (data_get($item->fields, 'block') ?? Statblock::empty());
        $currentFields = (array) ($item->fields ?? []);
        $document = $input['document'] ?? $item->document ?? '';

        try {
            $result = $drafter->draft(
                $item->item_type, $item->name, null, $currentBlock, $currentFields, $document, $input['prompt'], $input['history'] ?? [],
                new AiUsageContext('compendium_admin', null, $request->user()->id, $item->item_type),
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result === null) {
            return response()->json(['message' => 'The AI returned an unexpected response. Please try again.'], 422);
        }

        return response()->json($result);
    }
}
