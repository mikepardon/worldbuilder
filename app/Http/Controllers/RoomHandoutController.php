<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\RoomChanged;
use App\Models\Room;
use App\Models\RoomHandout;
use App\Support\Realtime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GM handouts shared with a battle room's table — an image, a note, or both. The GM owns these; players
 * only view them (server-side, every member receives them in the room payload).
 */
class RoomHandoutController extends Controller
{
    public function store(Request $request, Room $room)
    {
        $this->authorize('manage', $room->campaign);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:10000'],
            'image_media_id' => [
                'nullable',
                Rule::exists('media', 'id')->where('world_id', $room->campaign->world_id),
            ],
        ]);

        // A handout must carry something — an image, a note, or both.
        if (blank($data['body'] ?? null) && blank($data['image_media_id'] ?? null)) {
            return back()->withErrors(['body' => 'Add a note or pick an image to share.']);
        }

        $room->handouts()->create([
            'created_by' => $request->user()->id,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'image_media_id' => $data['image_media_id'] ?? null,
        ]);
        Realtime::poke(new RoomChanged($room->id));

        return back();
    }

    public function destroy(RoomHandout $roomHandout)
    {
        $this->authorize('manage', $roomHandout->room->campaign);

        $roomId = $roomHandout->room_id;
        $roomHandout->delete();
        Realtime::poke(new RoomChanged($roomId));

        return back();
    }
}
