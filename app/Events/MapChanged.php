<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A "map changed" poke broadcast to everyone viewing a map. It carries no map data — listeners
 * respond with a viewer-scoped Inertia reload, so visibility stays server-enforced (a player still
 * can't pull private pins/fog) and there is no client state to diff. Broadcast *now* (not queued) so
 * edits appear immediately; requires the Reverb server to be up when a mutation fires.
 */
class MapChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public int $mapId) {}

    public function broadcastOn(): Channel
    {
        return new Channel("maps.{$this->mapId}");
    }

    public function broadcastAs(): string
    {
        return 'MapChanged';
    }
}
