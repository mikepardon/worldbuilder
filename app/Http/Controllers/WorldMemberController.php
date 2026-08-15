<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\World;
use App\Models\WorldMember;
use App\Support\WorldNav;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Manage a world's co-authors — the accounts allowed to edit its content. Owner-only. */
class WorldMemberController extends Controller
{
    public function index(Request $request, World $world): Response
    {
        $this->authorize('manageMembers', $world);
        $world->load('owner:id,name,email');

        return Inertia::render('Worlds/Members', [
            'world' => WorldNav::for($world),
            'campaign' => ['id' => $world->id, 'name' => $world->name],
            'owner' => ['name' => $world->owner?->name, 'email' => $world->owner?->email],
            'members' => $world->members()->with('user:id,name,email')->latest()->get()
                ->map(fn (WorldMember $member) => [
                    'id' => $member->id,
                    'name' => $member->user?->name,
                    'email' => $member->user?->email,
                    'role' => $member->role,
                ]),
        ]);
    }

    public function store(Request $request, World $world): RedirectResponse
    {
        $this->authorize('manageMembers', $world);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            // editor = co-author (edits lore); moderator = runs play (campaigns/sessions).
            'role' => ['sometimes', 'in:editor,moderator'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if ($user === null) {
            return back()->with('error', 'No account with that email — ask them to sign up first.');
        }
        if ($user->id === $world->user_id) {
            return back()->with('error', "That's the world's owner — they already have full access.");
        }

        $world->members()->updateOrCreate(['user_id' => $user->id], ['role' => $data['role'] ?? 'editor']);

        return back()->with('success', "{$user->name} can now help with this world.");
    }

    public function update(Request $request, World $world, WorldMember $member): RedirectResponse
    {
        $this->authorize('manageMembers', $world);
        abort_unless($member->world_id === $world->id, 404);

        $data = $request->validate(['role' => ['required', 'in:editor,moderator']]);
        $member->update(['role' => $data['role']]);

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Request $request, World $world, WorldMember $member): RedirectResponse
    {
        $this->authorize('manageMembers', $world);
        abort_unless($member->world_id === $world->id, 404);

        $member->delete();

        return back()->with('success', 'Co-author removed.');
    }
}
