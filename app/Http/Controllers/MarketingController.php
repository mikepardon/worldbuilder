<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\World;
use App\Support\Plans;
use Brick\Money\Money;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class MarketingController extends Controller
{
    public function howItWorks(): Response
    {
        return $this->page('Marketing/HowItWorks');
    }

    public function pricing(): Response
    {
        return $this->page('Marketing/Pricing', ['plans' => $this->plans()]);
    }

    public function features(): Response
    {
        return $this->page('Marketing/Features');
    }

    public function faq(): Response
    {
        return $this->page('Marketing/Faq');
    }

    public function featureWorldbuilding(): Response
    {
        return $this->page('Marketing/Features/Worldbuilding');
    }

    public function featureVirtualTabletop(): Response
    {
        return $this->page('Marketing/Features/VirtualTabletop');
    }

    public function featureCompendium(): Response
    {
        return $this->page('Marketing/Features/Compendium');
    }

    public function featurePublishing(): Response
    {
        return $this->page('Marketing/Features/Publishing');
    }

    public function featureAi(): Response
    {
        return $this->page('Marketing/Features/Ai');
    }

    public function useCases(): Response
    {
        return $this->page('Marketing/UseCases');
    }

    public function examples(): Response
    {
        return $this->page('Marketing/Examples', ['worlds' => $this->publicWorlds()]);
    }

    public function compare(): Response
    {
        return $this->page('Marketing/Compare');
    }

    /**
     * Render a marketing page with the auth-availability flags every one of them needs.
     *
     * @param  array<string, mixed>  $props
     */
    private function page(string $component, array $props = []): Response
    {
        return Inertia::render($component, [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            ...$props,
        ]);
    }

    /**
     * A handful of the most recently updated public worlds, for the examples gallery.
     *
     * @return list<array{slug: string, name: string, description: string|null}>
     */
    private function publicWorlds(): array
    {
        return World::where('visibility', 'public')
            ->latest('updated_at')
            ->take(12)
            ->get()
            ->map(fn (World $world): array => [
                'slug' => (string) $world->slug,
                'name' => (string) $world->name,
                'description' => $world->description !== null ? (string) $world->description : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Plan tiers formatted for display. Prices are held in pence and formatted server-side with
     * brick/money so no monetary arithmetic happens in the frontend.
     *
     * @return list<array{
     *     key: string,
     *     name: string,
     *     price_display: string,
     *     is_free: bool,
     *     worlds: int,
     *     daily_credits: int,
     *     monthly_credits: int,
     *     storage_display: string,
     *     custom_domain: bool,
     *     blurb: string,
     * }>
     */
    private function plans(): array
    {
        return collect(Plans::all())->map(function (array $plan): array {
            $price = (int) $plan['price'];
            $isFree = $price === 0;

            return [
                'key' => (string) $plan['key'],
                'name' => (string) $plan['name'],
                'price_display' => $isFree
                    ? 'Free'
                    : Money::ofMinor($price, 'GBP')->formatToLocale('en_GB', allowWholeNumber: true),
                'is_free' => $isFree,
                'worlds' => (int) $plan['worlds'],
                'daily_credits' => (int) $plan['daily_credits'],
                'monthly_credits' => (int) $plan['monthly_credits'],
                'storage_display' => $this->storageDisplay((float) $plan['storage_gb']),
                'custom_domain' => (bool) $plan['custom_domain'],
                'blurb' => (string) $plan['blurb'],
            ];
        })->values()->all();
    }

    private function storageDisplay(float $gigabytes): string
    {
        return $gigabytes < 1.0
            ? (int) round($gigabytes * 1000).' MB'
            : (int) $gigabytes.' GB';
    }
}
