<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\RoomChanged;
use App\Models\Room;
use App\Models\RoomTemplate;
use App\Support\Realtime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Placed area-of-effect templates on a battle room's map. Any room member (or the GM) may drop one
 * when casting a spell; the creator or the GM may dismiss it.
 */
class RoomTemplateController extends Controller
{
    public function store(Request $request, Room $room)
    {
        $viewer = $request->user();
        abort_unless($viewer->can('manage', $room->campaign) || $room->isMember($viewer), Response::HTTP_FORBIDDEN);

        $isGm = $viewer->can('manage', $room->campaign);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['circle', 'cone', 'line', 'square'])],
            'layer' => ['sometimes', Rule::in(['token', 'gm'])],
            'scene' => ['sometimes', 'integer', Rule::exists('room_scenes', 'id')->where('room_id', $room->id)],
            'x' => ['required', 'numeric', 'between:0,100'],
            'y' => ['required', 'numeric', 'between:0,100'],
            'length' => ['required', 'numeric', 'min:1', 'max:2000'],
            'angle' => ['nullable', 'numeric', 'between:-360,360'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $room->templates()->create([
            'scene_id' => ($isGm ? ($data['scene'] ?? null) : null) ?? $room->active_scene_id,
            'created_by' => $viewer->id,
            'kind' => $data['kind'],
            // Only the GM may stage on the hidden layer; players always place on the token layer.
            'layer' => $isGm ? ($data['layer'] ?? 'token') : 'token',
            'x' => $data['x'],
            'y' => $data['y'],
            'length' => $data['length'],
            'angle' => $data['angle'] ?? 0,
            'color' => $data['color'] ?? '#d8a94a',
        ]);
        Realtime::poke(new RoomChanged($room->id, ['templates']));

        return back();
    }

    /** Move a template between layers (GM staging ↔ reveal). GM only. */
    public function update(Request $request, RoomTemplate $roomTemplate)
    {
        $this->authorize('manage', $roomTemplate->room->campaign);

        $data = $request->validate([
            'layer' => ['required', Rule::in(['token', 'gm'])],
        ]);

        $roomTemplate->update(['layer' => $data['layer']]);
        Realtime::poke(new RoomChanged($roomTemplate->room_id, ['templates']));

        return back();
    }

    public function destroy(Request $request, RoomTemplate $roomTemplate)
    {
        $viewer = $request->user();
        abort_unless(
            $viewer->can('manage', $roomTemplate->room->campaign) || $roomTemplate->created_by === $viewer->id,
            Response::HTTP_FORBIDDEN,
        );

        $roomId = $roomTemplate->room_id;
        $roomTemplate->delete();
        Realtime::poke(new RoomChanged($roomId, ['templates']));

        return back();
    }

    public function clear(Request $request, Room $room)
    {
        $this->authorize('manage', $room->campaign);

        $room->templates()->delete();
        Realtime::poke(new RoomChanged($room->id, ['templates']));

        return back();
    }
}
