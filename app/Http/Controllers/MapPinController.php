<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\MapChanged;
use App\Models\Map;
use App\Models\MapPin;
use App\Support\Realtime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Create, move, and remove pins on a map. Positions are percentages of the image (0–100). */
class MapPinController extends Controller
{
    public function store(Request $request, Map $map)
    {
        $this->authorize('manage', $map->world);

        $data = $this->validatePin($request, $map);

        $map->pins()->create($data);
        Realtime::poke(new MapChanged($map->id));

        return back();
    }

    public function update(Request $request, MapPin $pin)
    {
        $this->authorize('manage', $pin->map->world);

        $data = $this->validatePin($request, $pin->map, partial: true);

        $pin->update($data);
        Realtime::poke(new MapChanged($pin->map_id));

        // A background drag-to-move saves via a plain XHR (no Inertia visit); answer it with a bare
        // 204 so the editor keeps the pin where it was dropped instead of re-rendering the whole page.
        if (! $request->header('X-Inertia')) {
            return response()->noContent();
        }

        return back();
    }

    public function destroy(MapPin $pin)
    {
        $this->authorize('manage', $pin->map->world);

        $mapId = $pin->map_id;
        $pin->delete();
        Realtime::poke(new MapChanged($mapId));

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePin(Request $request, Map $map, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'x' => [$required, 'numeric', 'between:0,100'],
            'y' => [$required, 'numeric', 'between:0,100'],
            'label' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
            'behavior' => ['sometimes', 'in:info,travel,article'],
            'style' => ['sometimes', 'in:marker,token'],
            // A linked entry must live in the same world as the map.
            'document_id' => ['nullable', 'integer', Rule::exists('documents', 'id')->where('world_id', $map->world_id)],
            // A token may instead point at a compendium monster in this world.
            'compendium_item_id' => ['nullable', 'integer', Rule::exists('campaign_compendium_items', 'id')->where('world_id', $map->world_id)],
        ]);
    }
}
