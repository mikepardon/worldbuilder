<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompendiumItem;
use App\Models\CompendiumSource;
use App\Models\Document;
use App\Models\User;
use App\Models\World;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        // The default seed world, which admins may open and edit in the normal GM workspace (they pass
        // the world policies via the admin Gate::before). Null until the sandbox has been seeded.
        $seed = World::where('is_sandbox', true)->first();

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'users' => User::count(),
                'worlds' => World::count(),
                'entries' => Document::count(),
                'compendium' => CompendiumItem::count(),
            ],
            'seedWorld' => $seed === null ? null : [
                'id' => $seed->id,
                'name' => $seed->name,
                'slug' => $seed->slug,
                'entries' => $seed->documents()->count(),
            ],
            'alerts' => [
                'usersNoWorlds' => User::doesntHave('worlds')->count(),
                'unimportedSources' => CompendiumSource::doesntHave('items')->count(),
                'publicWorlds' => World::where('visibility', 'public')->count(),
            ],
            'newestUsers' => User::withCount('worlds')->latest()->take(6)->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_admin' => (bool) $user->is_admin,
                    'worlds_count' => $user->worlds_count,
                    'created_at' => $user->created_at?->toFormattedDateString(),
                ]),
            'recentWorlds' => World::with('owner:id,email')->withCount('documents')->latest('updated_at')->take(6)->get()
                ->map(fn (World $world) => [
                    'id' => $world->id,
                    'name' => $world->name,
                    'code' => $world->code, 'slug' => $world->slug,
                    'visibility' => $world->visibility,
                    'owner' => $world->owner?->email,
                    'documents_count' => $world->documents_count,
                ]),
        ]);
    }
}
