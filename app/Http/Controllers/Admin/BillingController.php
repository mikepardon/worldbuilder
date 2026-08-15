<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingSetting;
use App\Models\User;
use App\Support\Plans;
use Brick\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function index(): Response
    {
        $settings = BillingSetting::current();

        return Inertia::render('Admin/Billing', [
            'settings' => [
                'mode' => $settings->mode,
                // Publishable keys and price IDs are not secret, so they can be shown. Secret + webhook
                // values are never sent to the browser — only whether one is on file.
                'test' => $this->keySet($settings, 'test'),
                'live' => $this->keySet($settings, 'live'),
            ],
            'plans' => $this->plansOverview(),
            'creditsToday' => (int) User::whereDate('daily_ai_reset_on', now()->toDateString())->sum('daily_ai_used'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:sandbox,live'],
            'test_publishable_key' => ['nullable', 'string', 'max:255'],
            'test_secret_key' => ['nullable', 'string', 'max:255'],
            'test_webhook_secret' => ['nullable', 'string', 'max:255'],
            'test_price_basic' => ['nullable', 'string', 'max:255'],
            'test_price_pro' => ['nullable', 'string', 'max:255'],
            'live_publishable_key' => ['nullable', 'string', 'max:255'],
            'live_secret_key' => ['nullable', 'string', 'max:255'],
            'live_webhook_secret' => ['nullable', 'string', 'max:255'],
            'live_price_basic' => ['nullable', 'string', 'max:255'],
            'live_price_pro' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = BillingSetting::current();
        $settings->mode = $data['mode'];

        foreach (['test', 'live'] as $set) {
            // Non-secret fields: a blank value clears them (empty strings arrive as null).
            $settings->{"{$set}_publishable_key"} = $data["{$set}_publishable_key"] ?? null;
            $settings->{"{$set}_price_basic"} = $data["{$set}_price_basic"] ?? null;
            $settings->{"{$set}_price_pro"} = $data["{$set}_price_pro"] ?? null;

            // Secrets: only overwrite when a new value is supplied, so a blank field keeps the stored key.
            if (filled($data["{$set}_secret_key"] ?? null)) {
                $settings->{"{$set}_secret_key"} = $data["{$set}_secret_key"];
            }
            if (filled($data["{$set}_webhook_secret"] ?? null)) {
                $settings->{"{$set}_webhook_secret"} = $data["{$set}_webhook_secret"];
            }
        }

        $settings->save();

        return back()->with('success', 'Billing settings saved.');
    }

    /**
     * The non-secret view of one key set for the frontend.
     *
     * @return array{publishable_key: ?string, price_basic: ?string, price_pro: ?string, secret_set: bool, webhook_set: bool}
     */
    private function keySet(BillingSetting $settings, string $set): array
    {
        return [
            'publishable_key' => $settings->{"{$set}_publishable_key"},
            'price_basic' => $settings->{"{$set}_price_basic"},
            'price_pro' => $settings->{"{$set}_price_pro"},
            'secret_set' => filled($settings->{"{$set}_secret_key"}),
            'webhook_set' => filled($settings->{"{$set}_webhook_secret"}),
        ];
    }

    /**
     * Plan tiers with their entitlements and how many users are on each.
     *
     * @return list<array{key: string, name: string, price_display: string, daily_credits: int, worlds: int, subscribers: int}>
     */
    private function plansOverview(): array
    {
        $counts = User::query()->selectRaw('plan, count(*) as total')->groupBy('plan')->pluck('total', 'plan');

        return collect(Plans::all())->map(fn (array $plan): array => [
            'key' => (string) $plan['key'],
            'name' => (string) $plan['name'],
            'price_display' => (int) $plan['price'] === 0
                ? 'Free'
                : Money::ofMinor((int) $plan['price'], 'GBP')->formatToLocale('en_GB', allowWholeNumber: true),
            'daily_credits' => (int) $plan['daily_credits'],
            'worlds' => (int) $plan['worlds'],
            'subscribers' => (int) ($counts[$plan['key']] ?? 0),
        ])->values()->all();
    }
}
