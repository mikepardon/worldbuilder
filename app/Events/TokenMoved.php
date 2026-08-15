<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A token moved on the board. Unlike {@see RoomChanged} (a poke that triggers a scoped reload), this
 * carries the new position so clients patch it in place — no HTTP round-trip. Only broadcast for
 * non-GM-layer tokens: position is public, but a GM-layer token must never leak to players, and this
 * fans out on the shared public channel. GM-layer moves fall back to the viewer-scoped RoomChanged.
 */
class TokenMoved implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $roomId,
        public int $tokenId,
        public float $x,
        public float $y,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel("rooms.{$this->roomId}");
    }

    public function broadcastAs(): string
    {
        return 'TokenMoved';
    }
}
