<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Webhook;
use App\Models\World;
use App\Support\Webhooks;
use App\Support\WorldNav;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Manage a world's outbound webhooks. Owner-only (the reader/play integrations are sensitive). */
class WebhookController extends Controller
{
    public function index(World $world): Response
    {
        $this->authorize('update', $world);

        return Inertia::render('Worlds/Webhooks', [
            'world' => WorldNav::for($world),
            'events' => collect(Webhooks::EVENTS)->map(fn (string $label, string $key): array => [
                'key' => $key, 'label' => $label,
            ])->values(),
            'webhooks' => $world->webhooks()->latest()->get()
                ->map(fn (Webhook $webhook): array => $this->present($webhook)),
        ]);
    }

    public function store(Request $request, World $world): RedirectResponse
    {
        $this->authorize('update', $world);

        $data = $this->validated($request);

        $world->webhooks()->create([
            'url' => $data['url'],
            'events' => $data['events'],
            'is_active' => true,
            'secret' => 'whsec_'.Str::random(40),
        ]);

        return back();
    }

    public function update(Request $request, Webhook $webhook): RedirectResponse
    {
        $this->authorize('update', $webhook->world);

        $data = $this->validated($request, includeActive: true);

        $webhook->update($data);

        return back();
    }

    public function destroy(Webhook $webhook): RedirectResponse
    {
        $this->authorize('update', $webhook->world);

        $webhook->delete();

        return back();
    }

    /**
     * @return array{url: string, events: list<string>, is_active?: bool}
     */
    private function validated(Request $request, bool $includeActive = false): array
    {
        return $request->validate([
            'url' => ['required', 'url', 'max:255'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_keys(Webhooks::EVENTS))],
            ...$includeActive ? ['is_active' => ['required', 'boolean']] : [],
        ]);
    }

    /**
     * @return array{id: int, url: string, events: list<string>, is_active: bool, secret: string, created_at: string|null}
     */
    private function present(Webhook $webhook): array
    {
        return [
            'id' => $webhook->id,
            'url' => $webhook->url,
            'events' => $webhook->events ?? [],
            'is_active' => $webhook->is_active,
            // The owner needs the secret to verify signatures on their end.
            'secret' => (string) $webhook->secret,
            'created_at' => $webhook->created_at?->toIso8601String(),
        ];
    }
}
