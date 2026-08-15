<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A "room changed" poke broadcast to everyone in a battle room. Clients respond with a scoped Inertia
 * reload, so live token/fog/initiative changes sync without client-side state diffs (which couldn't
 * respect the per-viewer visibility gating anyway). `$only` names the Inertia props that changed
 * (`tokens`, `messages`, `drawings`, `templates`, `room`); an empty list reloads them all. Scoping it
 * means a chat message doesn't re-serialise every token's sheet, and vice versa. Broadcast *now* (not
 * queued) so updates arrive immediately — a queue hop added seconds of latency. Requires the Reverb
 * server to be up when a mutation fires.
 */
class RoomChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  list<string>  $only  Inertia props that changed; empty reloads the full room bundle.
     */
    public function __construct(public int $roomId, public array $only = []) {}

    public function broadcastOn(): Channel
    {
        return new Channel("rooms.{$this->roomId}");
    }

    public function broadcastAs(): string
    {
        return 'RoomChanged';
    }

    /**
     * @return array{only: list<string>}
     */
    public function broadcastWith(): array
    {
        return ['only' => $this->only];
    }
}
