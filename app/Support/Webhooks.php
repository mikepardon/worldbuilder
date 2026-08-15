<?php

declare(strict_types=1);

namespace App\Support;

use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use App\Models\World;

/** The outbound-webhook event catalogue and dispatch helper. */
class Webhooks
{
    /**
     * The events a webhook can subscribe to.
     *
     * @var array<string, string>
     */
    public const EVENTS = [
        'recap.published' => 'Session recap published',
        'session.scheduled' => 'Session scheduled',
        'rsvp.updated' => 'Player RSVP updated',
    ];

    /**
     * Fire an event to every active webhook on the world that subscribes to it.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function dispatch(World $world, string $event, array $payload): void
    {
        if (! array_key_exists($event, self::EVENTS)) {
            return;
        }

        $world->webhooks()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Webhook $webhook): bool => in_array($event, $webhook->events ?? [], true))
            ->each(fn (Webhook $webhook) => dispatch(new DeliverWebhook($webhook->id, $event, $payload)));
    }
}
