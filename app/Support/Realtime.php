<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Broadcasting\BroadcastException;

/**
 * Fire-and-forget real-time "poke" events. These are best-effort: they broadcast immediately when the
 * socket server (Reverb/Pusher) is up, but a connection failure is swallowed so a downed broadcast
 * server never fails the mutation that triggered it — the save has already happened.
 */
final class Realtime
{
    public static function poke(object $event): void
    {
        try {
            event($event);
        } catch (BroadcastException $exception) {
            report($exception);
        }
    }
}
