<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\World;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        // A verified custom domain serves its world's public reader at the root instead of the landing.
        $world = World::whereNotNull('custom_domain_verified_at')
            ->where('custom_domain', Str::lower($request->getHost()))
            ->first();
        if ($world !== null) {
            return app(PublicWorldController::class)->show($world);
        }

        return Inertia::render('Home', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'publicWorlds' => World::where('visibility', 'public')
                ->latest('updated_at')
                ->take(6)
                ->get()
                ->map(fn (World $world) => [
                    'code' => $world->code, 'slug' => $world->slug,
                    'name' => $world->name,
                    'description' => $world->description,
                ]),
        ]);
    }
}
