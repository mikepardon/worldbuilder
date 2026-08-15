<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\MapChanged;
use App\Models\Map;
use App\Models\Media;
use App\Models\World;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Create/update/delete a location's map + its base image. Managed from the location editor's Map tab. */
class MapController extends Controller
{
    /** Create the (single) map for a location; the GM manages it in the location editor's Map tab. */
    public function store(Request $request, World $world)
    {
        $this->authorize('editContent', $world);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'document_id' => ['nullable', 'integer', Rule::exists('documents', 'id')->where('world_id', $world->id)],
        ]);

        $world->maps()->create([
            'name' => $data['name'],
            'document_id' => $data['document_id'] ?? null,
            // A location's map is player-visible by default; the GM can hide it from the Map tab.
            'is_private' => false,
        ]);

        return back();
    }

    public function update(Request $request, World $world, Map $map)
    {
        $this->authorize('editContent', $world);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'image_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'document_id' => ['nullable', 'integer', Rule::exists('documents', 'id')->where('world_id', $world->id)],
            'is_private' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer'],
            'grid_visible' => ['sometimes', 'boolean'],
            'grid_size' => ['sometimes', 'integer', 'between:2,500'],
            'unit_size' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:12'],
            'real_width' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000000'],
            'distance_unit' => ['sometimes', 'nullable', 'in:feet,yards,miles,km'],
            'fog_enabled' => ['sometimes', 'boolean'],
            'fog' => ['nullable', 'array'],
            'fog.*' => ['string', 'max:16'], // "col,row" revealed cells
        ]);

        $map->update($data);
        Realtime::poke(new MapChanged($map->id));

        return back();
    }

    public function destroy(World $world, Map $map)
    {
        $this->authorize('editContent', $world);

        $map->delete();

        return back();
    }

    /** Upload a new base image for a map (stored in the world's media library) and attach it. */
    public function uploadImage(Request $request, World $world, Map $map): JsonResponse
    {
        $this->authorize('editContent', $world);

        $accept = config('media.accept');
        // Uppy posts the image here and expects JSON. Map images are large — allow beyond the 50 MB cap.
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:'.implode(',', $accept), 'max:51200'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first('file') ?: 'That image could not be uploaded.'], 422);
        }

        $disk = config('media.disk');
        $file = $request->file('file');
        if (! $request->user()->canStore((int) $file->getSize())) {
            return response()->json(['message' => 'Storage limit reached — delete some media or upgrade your plan for more space.'], 422);
        }

        $media = Media::create([
            'user_id' => $request->user()->id,
            'world_id' => $world->id,
            'disk' => $disk,
            'path' => $file->store('media', $disk),
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        $map->update(['image_media_id' => $media->id]);
        Realtime::poke(new MapChanged($map->id));

        return response()->json(['image_url' => $media->url]);
    }
}
