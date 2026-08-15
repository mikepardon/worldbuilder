<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Session;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Announce a freshly-published session recap to the world's Discord channel, if the GM connected one.
 * Best-effort: a missing webhook or a Discord outage must never disrupt publishing, so any failure is
 * reported and swallowed rather than retried.
 */
class NotifyDiscordRecap implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public int $sessionId) {}

    public function handle(): void
    {
        $session = Session::with(['campaign.world', 'recap'])->find($this->sessionId);

        if ($session === null) {
            return;
        }

        $world = $session->campaign->world;
        $webhook = $session->campaign->effectiveDiscordWebhook();

        if (blank($webhook)) {
            return;
        }

        $url = url("/w/{$world->slug}/campaigns/{$session->campaign->slug}/sessions/{$session->slug}");
        $summary = Str::limit((string) $session->recap?->recap_short, 1500);
        $content = "**{$session->title}** — a new session recap is up.\n\n{$summary}\n\n{$url}";

        try {
            Http::asJson()->post($webhook, ['content' => $content])->throw();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
