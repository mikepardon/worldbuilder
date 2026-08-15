<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\RoomChanged;
use App\Models\Room;
use App\Models\RoomScene;
use App\Support\Realtime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Scenes (maps) within a battle room — GM-managed: add, rename, delete, and switch the active one. */
class RoomSceneController extends Controller
{
    public function store(Request $request, Room $room)
    {
        $this->authorize('manage', $room->campaign);

        $data = $request->validate(['name' => ['nullable', 'string', 'max:120']]);

        $scene = $room->scenes()->create([
            'name' => $data['name'] ?? 'Scene '.($room->scenes()->count() + 1),
            'sort' => (int) $room->scenes()->max('sort') + 1,
        ]);
        // A new scene does NOT go live — the GM preps it first, then activates it explicitly.
        Realtime::poke(new RoomChanged($room->id));

        return back()->with('sceneId', $scene->id);
    }

    public function update(Request $request, RoomScene $roomScene)
    {
        $this->authorize('manage', $roomScene->room->campaign);

        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $roomScene->update(['name' => $data['name']]);
        Realtime::poke(new RoomChanged($roomScene->room_id));

        return back();
    }

    /**
     * Copy every token from another scene of the same room into this one, preserving all stats
     * (HP, conditions, spell slots, death saves, layer, position, ownership, …). Handy when the party
     * moves to a new map mid-encounter.
     */
    public function importTokens(Request $request, RoomScene $roomScene)
    {
        $room = $roomScene->room;
        $this->authorize('manage', $room->campaign);

        $data = $request->validate([
            'from' => [
                'required', 'integer', 'different:'.$roomScene->id,
                Rule::exists('room_scenes', 'id')->where('room_id', $room->id),
            ],
        ]);

        $sources = $room->tokens()->where('scene_id', $data['from'])->get();

        DB::transaction(function () use ($sources, $roomScene): void {
            foreach ($sources as $token) {
                $copy = $token->replicate(); // copies every attribute (stats/hp/conditions/json) but the id
                $copy->scene_id = $roomScene->id;
                $copy->save();
            }
        });
        Realtime::poke(new RoomChanged($room->id));

        return back();
    }

    /** Make this scene the active board for its room. */
    public function activate(RoomScene $roomScene)
    {
        $this->authorize('manage', $roomScene->room->campaign);

        $roomScene->room->forceFill(['active_scene_id' => $roomScene->id])->save();
        Realtime::poke(new RoomChanged($roomScene->room_id));

        return back();
    }

    public function destroy(RoomScene $roomScene)
    {
        $room = $roomScene->room;
        $this->authorize('manage', $room->campaign);

        if ($room->scenes()->count() <= 1) {
            throw ValidationException::withMessages(['scene' => 'A room needs at least one scene.']);
        }

        $wasActive = $room->active_scene_id === $roomScene->id;
        $roomScene->delete(); // cascades its tokens/templates/drawings

        if ($wasActive) {
            $room->forceFill(['active_scene_id' => $room->scenes()->orderBy('sort')->value('id')])->save();
        }
        Realtime::poke(new RoomChanged($room->id));

        return back();
    }
}
