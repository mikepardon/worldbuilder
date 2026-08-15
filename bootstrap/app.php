<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Behind a proxy/tunnel/load balancer (ngrok in dev, an LB in production), honour the forwarded
        // host and scheme so generated URLs — asset links especially — use the real public origin over
        // HTTPS rather than the local upstream host.
        $middleware->trustProxies(at: '*');

        // Stripe signs its webhooks and Deepgram posts to a signed callback URL; neither carries a
        // session/CSRF token. Each request's authenticity is verified another way (signature / signed URL).
        $middleware->validateCsrfTokens(except: ['stripe/webhook', 'webhooks/deepgram/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Report unhandled exceptions to Sentry (no-op unless SENTRY_LARAVEL_DSN is configured).
        Integration::handles($exceptions);
    })->create();
