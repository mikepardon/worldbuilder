<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ApplyWorldIngestion;
use App\Jobs\PlanWorldIngestion;
use App\Models\World;
use App\Models\WorldIngestion;
use App\Support\WorldNav;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Add knowledge to an existing world": the user pastes freeform notes, {@see PlanWorldIngestion} proposes
 * a reviewable plan, the user approves a subset, and {@see ApplyWorldIngestion} writes them on the queue,
 * credit-gated (pausing when the account runs dry). The page polls {@see status()} throughout.
 */
class WorldIngestionController extends Controller
{
    /** The ingestion page, showing the most recent run's state (input, review, or progress). */
    public function index(World $world): Response
    {
        $this->authorize('manage', $world);
        $this->ensureEnabled($world);

        $ingestion = $world->worldIngestions()->latest()->first();

        return Inertia::render('Worlds/Ingest', [
            'world' => WorldNav::for($world),
            'campaign' => ['id' => $world->id, 'name' => $world->name],
            'ingestion' => $ingestion?->toStatusArray(),
        ]);
    }

    /** Start a new ingestion: store the notes and queue the (cheap) planning pass. */
    public function store(Request $request, World $world): JsonResponse
    {
        $this->authorize('manage', $world);
        $this->ensureEnabled($world);

        $data = $request->validate([
            'source_text' => ['required', 'string', 'min:20', 'max:20000'],
        ]);

        $ingestion = $world->worldIngestions()->create([
            'user_id' => $request->user()->id,
            'status' => 'planning',
            'source_text' => $data['source_text'],
            'message' => 'Queued — reviewing your notes…',
        ]);

        dispatch(new PlanWorldIngestion($ingestion));

        // Reflect DB truth: with a sync queue the plan has already run; on a real queue it's still planning.
        return response()->json(['ingestion' => $ingestion->refresh()->toStatusArray()]);
    }

    /** Live status, polled by the page while planning or applying. */
    public function status(World $world, WorldIngestion $ingestion): JsonResponse
    {
        $this->authorize('manage', $world);
        $this->ensureEnabled($world);
        $this->ensureBelongs($world, $ingestion);

        return response()->json(['ingestion' => $ingestion->toStatusArray()]);
    }

    /** Record the reviewer's approvals and queue the credit-gated apply pass. */
    public function apply(Request $request, World $world, WorldIngestion $ingestion): JsonResponse
    {
        $this->authorize('manage', $world);
        $this->ensureEnabled($world);
        $this->ensureBelongs($world, $ingestion);

        if ($ingestion->status !== 'ready') {
            return response()->json(['message' => 'This ingestion is not awaiting review.'], 422);
        }

        $data = $request->validate([
            'approved' => ['present', 'array'],
            'approved.*' => ['integer'],
        ]);

        $approvedIds = array_values(array_map('intval', $data['approved']));

        // Approve exactly the chosen changes; everything else is rejected so the apply pass skips it.
        $ingestion->proposedChanges()->whereIn('id', $approvedIds)->update(['decision' => 'approved']);
        $ingestion->proposedChanges()->whereNotIn('id', $approvedIds)->update(['decision' => 'rejected']);

        $planned = $ingestion->proposedChanges()->where('decision', 'approved')->count();
        if ($planned === 0) {
            return response()->json(['message' => 'Select at least one change to apply.'], 422);
        }

        $ingestion->update(['status' => 'applying', 'planned' => $planned, 'applied' => 0, 'cursor' => 0, 'error' => null]);
        $ingestion->pushLog("Applying {$planned} approved ".Str::plural('change', $planned).'…');

        dispatch(new ApplyWorldIngestion($ingestion));

        return response()->json(['ingestion' => $ingestion->fresh()->toStatusArray()]);
    }

    /** Resume a run that paused when the account ran out of credits. */
    public function resume(Request $request, World $world, WorldIngestion $ingestion): JsonResponse
    {
        $this->authorize('manage', $world);
        $this->ensureEnabled($world);
        $this->ensureBelongs($world, $ingestion);

        if ($ingestion->status !== 'paused') {
            return response()->json(['message' => 'This ingestion is not paused.'], 422);
        }

        if (! $request->user()->canSpendAiCredit()) {
            return response()->json(['message' => 'You’re still out of AI credits. Top up or upgrade to continue.', 'outOfCredits' => true], 402);
        }

        $ingestion->update(['status' => 'applying', 'error' => null]);
        $ingestion->pushLog('Resuming…');
        dispatch(new ApplyWorldIngestion($ingestion));

        return response()->json(['ingestion' => $ingestion->fresh()->toStatusArray()]);
    }

    /** Discard an ingestion (its proposed changes cascade away; applied entries are untouched). */
    public function destroy(World $world, WorldIngestion $ingestion): JsonResponse
    {
        $this->authorize('manage', $world);
        $this->ensureEnabled($world);
        $this->ensureBelongs($world, $ingestion);

        $ingestion->delete();

        return response()->json(['deleted' => true]);
    }

    private function ensureBelongs(World $world, WorldIngestion $ingestion): void
    {
        abort_unless($ingestion->world_id === $world->id, 404);
    }

    /** Knowledge ingestion is an admin-granted, per-world feature (like the D&D Beyond importer). */
    private function ensureEnabled(World $world): void
    {
        abort_unless($world->knowledge_ingestion_enabled, 403, 'Knowledge ingestion is not enabled for this world. Ask an admin.');
    }
}
