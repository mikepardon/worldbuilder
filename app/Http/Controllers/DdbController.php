<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\RunDdbImport;
use App\Models\World;
use App\Services\DdbClient;
use App\Support\WorldNav;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The per-world D&D Beyond flow: a keys page (Cobalt token + campaign selection) and an import page
 * that kicks off a background {@see RunDdbImport} job and shows its live progress. Admin-gated per
 * world via the world's ddb_enabled flag.
 */
class DdbController extends Controller
{
    /** The keys page: manage the Cobalt token and the shared DDB campaign. */
    public function settings(World $world)
    {
        $this->authorize('editContent', $world);
        abort_unless($world->ddb_enabled, 403, 'D&D Beyond import is not enabled for this world. Ask an admin.');

        return Inertia::render('Compendium/Ddb/Settings', [
            'world' => WorldNav::for($world),
            'campaign' => [
                'id' => $world->id,
                'name' => $world->name,
                'ddb_saved' => filled($world->ddbCobalt()),
                'ddb_campaign_id' => $world->ddb_campaign_id,
            ],
            // Flashed by fetchCampaigns() on the previous request; empty on a fresh load.
            'ddbCampaigns' => session('ddbCampaigns', []),
        ]);
    }

    /** Persist the Cobalt token (validated against DDB) and the selected campaign. */
    public function saveSettings(Request $request, World $world, DdbClient $ddb)
    {
        $this->authorize('editContent', $world);
        abort_unless($world->ddb_enabled, 403);

        $data = $request->validate([
            'cobalt' => ['nullable', 'string', 'max:4000'],
            'ddb_campaign_id' => ['nullable', 'string', 'max:64'],
        ]);

        $pasted = trim((string) ($data['cobalt'] ?? ''));
        if ($pasted !== '') {
            if ($ddb->token($pasted) === null) {
                throw ValidationException::withMessages(['cobalt' => 'That Cobalt token was rejected by D&D Beyond. Copy a fresh one and try again.']);
            }
            $world->ddb_cobalt = $pasted;
        }

        $world->ddb_campaign_id = filled($data['ddb_campaign_id'] ?? null) ? $data['ddb_campaign_id'] : null;
        $world->save();

        return back()->with('success', 'D&D Beyond settings saved.');
    }

    /** Validate the token and list the GM's DDB campaigns for selection. */
    public function fetchCampaigns(Request $request, World $world, DdbClient $ddb)
    {
        $this->authorize('editContent', $world);
        abort_unless($world->ddb_enabled, 403);

        $data = $request->validate(['cobalt' => ['nullable', 'string', 'max:4000']]);

        $pasted = trim((string) ($data['cobalt'] ?? ''));
        $cobalt = $pasted !== '' ? $pasted : (string) ($world->ddbCobalt() ?? '');
        if ($cobalt === '') {
            throw ValidationException::withMessages(['cobalt' => 'Paste your D&D Beyond Cobalt session token first.']);
        }

        $bearer = $ddb->token($cobalt);
        if ($bearer === null) {
            throw ValidationException::withMessages(['cobalt' => 'That Cobalt token was rejected by D&D Beyond. Copy a fresh one and try again.']);
        }

        // A working token pasted here is worth remembering (encrypted) so the GM needn't re-paste.
        if ($pasted !== '') {
            $world->update(['ddb_cobalt' => $pasted]);
        }

        $campaigns = $ddb->campaigns($bearer);

        return back()
            ->with('ddbCampaigns', $campaigns)
            ->with('success', $campaigns === [] ? 'Token valid — no shared campaigns found.' : 'Token valid — '.count($campaigns).' campaign(s) found.');
    }

    /** The import page: choose what to import and watch a running import's progress. */
    public function importPage(Request $request, World $world)
    {
        $this->authorize('editContent', $world);
        abort_unless($world->ddb_enabled, 403, 'D&D Beyond import is not enabled for this world. Ask an admin.');

        $activeId = $request->integer('import');
        $active = $activeId > 0
            ? $world->compendiumImports()->whereKey($activeId)->first()
            : $world->compendiumImports()->latest()->first();

        return Inertia::render('Compendium/Ddb/Import', [
            'world' => WorldNav::for($world),
            'campaign' => [
                'id' => $world->id,
                'name' => $world->name,
                'ddb_saved' => filled($world->ddbCobalt()),
                'ddb_campaign_id' => $world->ddb_campaign_id,
            ],
            'activeImport' => $active?->toStatusArray(),
        ]);
    }

    /** Queue a background import and redirect to the progress view. */
    public function startImport(Request $request, World $world)
    {
        $this->authorize('editContent', $world);
        abort_unless($world->ddb_enabled, 403);

        $data = $request->validate([
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['in:monster,item,spell'],
            'homebrew' => ['sometimes', 'boolean'],
        ]);

        if (blank($world->ddbCobalt())) {
            throw ValidationException::withMessages(['types' => 'Save your D&D Beyond token on the keys page first.']);
        }

        $import = $world->compendiumImports()->create([
            'user_id' => $request->user()->id,
            'source' => 'ddb',
            'status' => 'queued',
            'types' => array_values($data['types']),
            'homebrew' => (bool) ($data['homebrew'] ?? true),
            'ddb_campaign_id' => $world->ddb_campaign_id,
            'message' => 'Queued — waiting for a worker…',
        ]);

        dispatch(new RunDdbImport($import));

        return redirect()->route('ddb.import', [$world, 'import' => $import->id]);
    }
}
