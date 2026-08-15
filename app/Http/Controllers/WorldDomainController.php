<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\World;
use App\Services\Dns\DnsResolver;
use App\Support\WorldNav;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Setting up and verifying a world's custom domain (a Basic/Pro entitlement). */
class WorldDomainController extends Controller
{
    public function edit(Request $request, World $world): Response
    {
        $this->authorize('update', $world);

        return Inertia::render('Worlds/Domain', [
            'world' => WorldNav::for($world),
            'domain' => $world->custom_domain,
            'verified' => $world->hasVerifiedDomain(),
            'targetIp' => (string) config('domains.ip'),
            'canUseCustomDomain' => (bool) $world->owner?->canUseCustomDomain(),
        ]);
    }

    public function update(Request $request, World $world): RedirectResponse
    {
        $this->authorize('update', $world);

        if (! $world->owner?->canUseCustomDomain()) {
            return back()->with('error', 'Custom domains are available on the Basic and Pro plans.');
        }

        // Normalise before validating so uniqueness and the "not our own host" check are case-stable.
        $request->merge(['custom_domain' => Str::lower(trim((string) $request->input('custom_domain')))]);

        $data = $request->validate([
            'custom_domain' => [
                'required', 'string', 'max:253',
                'regex:/^(?!-)[a-z0-9-]{1,63}(\.[a-z0-9-]{1,63})+$/',
                Rule::unique('worlds', 'custom_domain')->ignore($world->id),
                Rule::notIn([parse_url((string) config('app.url'), PHP_URL_HOST)]),
            ],
        ], [
            'custom_domain.regex' => 'Enter a valid domain, e.g. world.example.com.',
            'custom_domain.not_in' => "That's this site's own domain.",
            'custom_domain.unique' => 'That domain is already connected to another world.',
        ]);

        // Changing the domain resets verification — the new domain must be checked afresh.
        $world->update([
            'custom_domain' => $data['custom_domain'],
            'custom_domain_verified_at' => null,
        ]);

        return back()->with('success', 'Domain saved. Add the A record shown below, then verify it.');
    }

    public function verify(Request $request, World $world, DnsResolver $dns): RedirectResponse
    {
        $this->authorize('update', $world);

        if (blank($world->custom_domain)) {
            return back()->with('error', 'Set a domain first.');
        }

        $targetIp = (string) config('domains.ip');
        if (blank($targetIp)) {
            return back()->with('error', "Custom domains aren't configured on this server yet — ask an administrator.");
        }

        if (in_array($targetIp, $dns->aRecords($world->custom_domain), true)) {
            $world->update(['custom_domain_verified_at' => now()]);

            return back()->with('success', 'Your domain is connected.');
        }

        return back()->with('error', "We couldn't find an A record for {$world->custom_domain} pointing to {$targetIp} yet. DNS can take a while to propagate — try again shortly.");
    }

    public function destroy(Request $request, World $world): RedirectResponse
    {
        $this->authorize('update', $world);

        $world->update(['custom_domain' => null, 'custom_domain_verified_at' => null]);

        return back()->with('success', 'Custom domain removed.');
    }
}
