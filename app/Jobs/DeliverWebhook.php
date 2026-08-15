<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Webhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Delivers one event to one {@see Webhook}: a JSON POST signed with the webhook's secret. Retried a few
 * times on failure so a transient outage doesn't lose the event; a permanent failure lands in failed_jobs.
 *
 * @param  array<string, mixed>  $payload
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /** @param array<string, mixed> $payload */
    public function __construct(public int $webhookId, public string $event, public array $payload) {}

    public function handle(): void
    {
        $webhook = Webhook::find($this->webhookId);

        if ($webhook === null || ! $webhook->is_active) {
            return;
        }

        $body = json_encode(
            ['event' => $this->event, ...$this->payload],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $signature = hash_hmac('sha256', $body, (string) $webhook->secret);

        Http::withHeaders([
            'X-Worldbuilder-Event' => $this->event,
            'X-Worldbuilder-Signature' => "sha256={$signature}",
        ])->withBody($body, 'application/json')->post($webhook->url)->throw();
    }
}
