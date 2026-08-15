<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CloneCampaign;
use App\Models\World;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The seeded "sandbox" world: a read-only demo every user can explore from a player's perspective, then
 * copy into a private, fully-editable world of their own.
 */
class SandboxController extends Controller
{
    /** Open the sandbox in the public reader — the player-perspective, read-only view. */
    public function view(): RedirectResponse
    {
        $sandbox = World::where('is_sandbox', true)->first();
        abort_if($sandbox === null, Response::HTTP_NOT_FOUND, 'The sandbox world has not been seeded yet.');

        return redirect()->route('public.world', $sandbox->slug);
    }

    /** Clone the sandbox into a new private world owned by the signed-in user, then open its workspace. */
    public function clone(Request $request, CloneCampaign $cloner): RedirectResponse
    {
        $sandbox = World::where('is_sandbox', true)->firstOrFail();

        $world = $cloner->handle($sandbox, $request->user(), [
            'name' => $sandbox->name,
            'visibility' => 'private',
        ]);

        return redirect()->route('worlds.show', $world)
            ->with('success', 'Your copy of the sandbox world is ready — edit it freely, or make it public to go live.');
    }
}
