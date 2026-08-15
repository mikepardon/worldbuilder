<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\World;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates an unlisted world's reader behind its reader password. The owner and campaign members bypass
 * it; everyone else is bounced to the unlock screen until they enter the password (held in the session).
 */
class EnforceReaderPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $world = $request->route('world');

        if (! $world instanceof World || $world->visibility !== 'hidden' || ! $world->hasReaderPassword()) {
            return $next($request);
        }

        if ($world->readerPasswordBypassedBy($request->user())) {
            return $next($request);
        }

        if ($request->session()->get("world_unlocked.{$world->id}", false) === true) {
            return $next($request);
        }

        return redirect()->route('public.world.locked', $world);
    }
}
