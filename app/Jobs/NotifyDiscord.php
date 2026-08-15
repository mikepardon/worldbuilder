<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Post a message to a campaign's Discord channel (its own webhook, else the world's). Best-effort: a
 * missing webhook or a Discord outage must never disrupt the action that triggered it, so any failure
 * is reported and swallowed rather than retried.
 */
class NotifyDiscord implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public int $campaignId, public string $content) {}

    public function handle(): void
    {
        $campaign = Campaign::with('world')->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        $webhook = $campaign->effectiveDiscordWebhook();

        if (blank($webhook)) {
            return;
        }

        try {
            Http::asJson()->post($webhook, ['content' => $this->content])->throw();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
