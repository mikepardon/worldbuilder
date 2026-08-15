<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\RoomChanged;
use App\Models\Room;
use App\Models\RoomDrawing;
use App\Support\Realtime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GM freehand/shape annotations on a battle room's map. The GM draws; everyone sees them live. Points
 * are map percentages (resolution-independent), validated and stored as JSON.
 */
class RoomDrawingController extends Controller
{
    public function store(Request $request, Room $room)
    {
        $this->authorize('manage', $room->campaign);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['freehand', 'line', 'rect', 'ellipse'])],
            'layer' => ['sometimes', Rule::in(['token', 'gm'])],
            'scene' => ['sometimes', 'integer', Rule::exists('room_scenes', 'id')->where('room_id', $room->id)],
            'points' => ['required', 'array', 'min:2', 'max:2000'],
            'points.*.x' => ['required', 'numeric', 'between:-50,150'],
            'points.*.y' => ['required', 'numeric', 'between:-50,150'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'fill' => ['sometimes', 'boolean'],
        ]);

        $room->drawings()->create([
            'scene_id' => ($data['scene'] ?? null) ?? $room->active_scene_id,
            'created_by' => $request->user()->id,
            'kind' => $data['kind'],
            'layer' => $data['layer'] ?? 'token',
            'points' => $data['points'],
            'color' => $data['color'] ?? '#e0743c',
            'fill' => $data['fill'] ?? false,
        ]);
        Realtime::poke(new RoomChanged($room->id, ['drawings']));

        return back();
    }

    public function destroy(RoomDrawing $roomDrawing)
    {
        $this->authorize('manage', $roomDrawing->room->campaign);

        $roomId = $roomDrawing->room_id;
        $roomDrawing->delete();
        Realtime::poke(new RoomChanged($roomId, ['drawings']));

        return back();
    }

    public function clear(Room $room)
    {
        $this->authorize('manage', $room->campaign);

        $room->drawings()->delete();
        Realtime::poke(new RoomChanged($room->id, ['drawings']));

        return back();
    }
}
