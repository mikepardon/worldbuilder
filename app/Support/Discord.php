<?php

declare(strict_types=1);

namespace App\Support;

use App\Jobs\NotifyDiscord;
use App\Models\ScheduleEvent;

/**
 * Builds and queues Discord announcements for campaign events. Message formatting lives here so the
 * controllers that trigger them stay thin; delivery (and the campaign→world webhook fallback) is the
 * {@see NotifyDiscord} job's concern.
 */
class Discord
{
    /** Announce a newly-scheduled session to the campaign's Discord channel. */
    public static function sessionScheduled(ScheduleEvent $event): void
    {
        $campaign = $event->campaign;
        $world = $campaign->world;
        $url = url("/w/{$world->slug}/campaigns/{$campaign->slug}/schedule");
        $when = $event->starts_at?->format('D j M Y, H:i') ?? 'To be confirmed';

        $content = "**New session scheduled** — {$event->title}\n🗓️ {$when}\n{$url}";

        dispatch(new NotifyDiscord($campaign->id, $content));
    }
}
