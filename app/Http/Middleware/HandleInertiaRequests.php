<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AiModels;
use App\Support\CreditWeights;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // Public broadcast settings for the Echo client — null (realtime off) unless a Pusher-
            // protocol broadcaster (Reverb or Pusher/soketi) is active. The secret is never exposed.
            'broadcast' => $this->broadcastConfig(),
            // The assistant's product name (e.g. "Muse"). The underlying model is never shared to users.
            'ai' => ['name' => fn () => AiModels::name()],
            // The signed-in GM's AI credit standing and the per-action cost schedule, so the header pill
            // and the "this costs N credits" hints can render everywhere without a per-page fetch.
            'credits' => fn () => $this->creditsFor($request->user()),
        ];
    }

    /**
     * The signed-in user's live AI credit standing plus the front-end cost schedule, or null when nobody
     * is signed in. Daily free credits are always spent before the purchased balance.
     *
     * @return array{remaining: int, daily_allowance: int, daily_used: int, balance: int, costs: array{kinds: array<string, int>, actions: array<string, int>}}|null
     */
    private function creditsFor(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'remaining' => $user->aiCreditsRemaining(),
            'daily_allowance' => $user->dailyAiAllowance(),
            'daily_used' => $user->aiUsedToday(),
            'balance' => (int) $user->ai_credit_balance,
            'costs' => CreditWeights::uiCostMap(),
        ];
    }

    /**
     * The client-safe broadcasting config for the active driver (Reverb or Pusher), or null when
     * realtime is disabled. Both speak the Pusher protocol, so the Echo client handles either.
     *
     * @return array{driver: string, key: string|null, host: string|null, port: int, scheme: string, cluster: string|null}|null
     */
    private function broadcastConfig(): ?array
    {
        $driver = config('broadcasting.default');
        if (! in_array($driver, ['reverb', 'pusher'], true)) {
            return null;
        }

        $options = config("broadcasting.connections.{$driver}.options");

        return [
            'driver' => $driver,
            'key' => config("broadcasting.connections.{$driver}.key"),
            'host' => $options['host'] ?? null,
            'port' => (int) ($options['port'] ?? 443),
            'scheme' => $options['scheme'] ?? 'https',
            'cluster' => $options['cluster'] ?? null,
        ];
    }
}
