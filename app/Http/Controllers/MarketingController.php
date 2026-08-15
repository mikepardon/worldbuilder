<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Plans;
use Brick\Money\Money;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class MarketingController extends Controller
{
    public function howItWorks(): Response
    {
        return Inertia::render('Marketing/HowItWorks', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
        ]);
    }

    public function pricing(): Response
    {
        return Inertia::render('Marketing/Pricing', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'plans' => $this->plans(),
        ]);
    }

    public function features(): Response
    {
        return Inertia::render('Marketing/Features', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
        ]);
    }

    public function faq(): Response
    {
        return Inertia::render('Marketing/Faq', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
        ]);
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
