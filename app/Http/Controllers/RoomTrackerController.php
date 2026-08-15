<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\RoomChanged;
use App\Models\Room;
use App\Models\RoomToken;
use App\Support\Realtime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The GM's initiative tracker actions over a whole room: pull every token onto the tracker, roll a
 * fresh initiative for everyone at once, or clear the tracker. Per-combatant edits (HP, a single
 * initiative) go through {@see RoomTokenController::update}.
 */
class RoomTrackerController extends Controller
{
    public function store(Request $request, Room $room)
    {
        $this->authorize('manage', $room->campaign);

        $action = $request->validate(['action' => ['required', 'in:add_all,roll_all,clear']])['action'];

        match ($action) {
            'add_all' => $this->addAll($room),
            'roll_all' => $this->rollAll($room),
            'clear' => $this->clear($room),
        };

        Realtime::poke(new RoomChanged($room->id));

        return back();
    }

    /** Put every token on the tracker, rolling initiative for any that lack one. */
    private function addAll(Room $room): void
    {
        DB::transaction(function () use ($room): void {
            $room->tokens()->get()->each(function (RoomToken $token): void {
                $token->update([
                    'in_tracker' => true,
                    'initiative' => $token->initiative ?? random_int(1, 20),
                ]);
            });
        });
    }

    /** Re-roll initiative (d20) for everyone currently on the tracker. */
    private function rollAll(Room $room): void
    {
        DB::transaction(function () use ($room): void {
            $room->tokens()->where('in_tracker', true)->get()
                ->each(fn (RoomToken $token) => $token->update(['initiative' => random_int(1, 20)]));
        });
    }

    /** Empty the tracker and reset the turn to the top of round one. */
    private function clear(Room $room): void
    {
        DB::transaction(function () use ($room): void {
            $room->tokens()->update(['in_tracker' => false]);
            $room->update(['active_token_id' => null, 'round' => 1]);
        });
    }
}
