<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Exposed routes
    |--------------------------------------------------------------------------
    |
    | Ziggy serialises the named routes listed here into every page via the
    | `@routes` Blade directive, making them callable from the frontend through
    | the `route()` helper. Without an allowlist Ziggy exposes *every* route —
    | including signed webhook receivers and other server-only endpoints — which
    | is a needless information leak.
    |
    | This list is an allowlist of the name prefixes the Vue frontend actually
    | calls `route()` with. Patterns use Laravel's `Str::is` wildcard matching,
    | so a `foo.*` entry also covers any future sibling route under that prefix.
    | Anything not matched here is withheld from the frontend. Route access is
    | still enforced server-side; this only controls what the browser can see.
    |
    | When you add a frontend `route('name')` call under a new prefix, add the
    | prefix here or the call will throw at runtime.
    |
    */

    'only' => [
        // Root / unprefixed
        'home',
        'dashboard',
        'login',
        'logout',
        'register',

        // Auth & account
        'password.*',
        'verification.*',
        'profile.*',

        // Marketing
        'marketing.*',

        // Billing
        'billing.*',

        // Async AI request polling
        'ai.*',

        // Storage breakdown
        'storage.*',

        // Worlds & their content
        'worlds.*',
        'documents.*',
        'compendium.*',
        'generators.*',
        'tables.*',
        'media.*',
        'members.*',
        'invites.*',
        'webhooks.*',

        // Campaigns, sessions & scheduling
        'campaigns.*',
        'sessions.*',
        'session-notes.*',
        'schedule.*',
        'calendars.*',
        'characters.*',
        'players.*',
        'join.*',

        // Recaps & entity linking
        'recap.*',

        // Virtual tabletop rooms
        'rooms.*',
        'room-tokens.*',
        'room-messages.*',
        'maps.*',
        'map-pins.*',

        // Notes
        'notes.*',
        'user-notes.*',

        // Search
        'search.*',

        // D&D Beyond integration
        'ddb.*',

        // Compendium sandbox
        'sandbox.*',

        // Public player-facing reader
        'public.*',

        // Admin panel (Inertia; access enforced server-side)
        'admin.*',
    ],
];
