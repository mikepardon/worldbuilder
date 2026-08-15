<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomNote;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A player's private journal for a battle room — session notes visible only to its author. */
class RoomNoteController extends Controller
{
    public function store(Request $request, Room $room)
    {
        $viewer = $request->user();
        abort_unless($viewer->can('manage', $room->campaign) || $room->isMember($viewer), Response::HTTP_FORBIDDEN);

        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);

        $room->notes()->create(['user_id' => $viewer->id, 'body' => $data['body']]);

        return back();
    }

    public function destroy(Request $request, RoomNote $roomNote)
    {
        abort_unless($roomNote->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
        $roomNote->delete();

        return back();
    }
}
