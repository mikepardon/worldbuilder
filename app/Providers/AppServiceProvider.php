<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\StripeBillingGateway;
use App\Services\Dns\DnsResolver;
use App\Services\Dns\SystemDnsResolver;
use App\Services\Transcription\DeepgramTranscriber;
use App\Services\Transcription\FakeTranscriber;
use App\Services\Transcription\Transcriber;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped (not singleton) for Octane compatibility; the gateway reads live settings per request.
        $this->app->scoped(BillingGateway::class, StripeBillingGateway::class);
        $this->app->scoped(DnsResolver::class, SystemDnsResolver::class);

        // Real speech-to-text via Deepgram when configured; the placeholder keeps local/dev and tests
        // working without a key. Swapping to a self-hosted model later is just another binding here.
        $this->app->scoped(
            Transcriber::class,
            fn () => filled(config('services.deepgram.key')) ? new DeepgramTranscriber : new FakeTranscriber,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Platform admins bypass every policy check.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        // Explicit gate for the admin area.
        Gate::define('access-admin', fn (User $user) => $user->isAdmin());
    }
}
