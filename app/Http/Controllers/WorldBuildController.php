<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\PopulateWorldFromSeed;
use App\Models\World;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kicks off and reports on a background "build my world from its seed" run. The dashboard starts one and
 * polls its progress; the heavy lifting happens in {@see PopulateWorldFromSeed}.
 */
class WorldBuildController extends Controller
{
    /** Queue a build of the world from its seed notes, reusing an in-flight one if it exists. */
    public function store(Request $request, World $world): JsonResponse
    {
        $this->authorize('manage', $world);

        if (blank($world->setting)) {
            return response()->json(['message' => 'Add a world description first — that’s what we build from.'], 422);
        }

        $build = $world->worldBuilds()->whereIn('status', ['queued', 'running'])->latest()->first();
        if ($build === null) {
            $build = $world->worldBuilds()->create([
                'user_id' => $request->user()->id,
                'status' => 'queued',
                'message' => 'Queued — warming up…',
            ]);

            dispatch(new PopulateWorldFromSeed($build));
        }

        return response()->json(['build' => $build->toStatusArray()]);
    }

    /** The latest build's live status, polled by the dashboard while a build runs. */
    public function status(World $world): JsonResponse
    {
        $this->authorize('manage', $world);

        $build = $world->worldBuilds()->latest()->first();

        return response()->json(['build' => $build?->toStatusArray()]);
    }
}
