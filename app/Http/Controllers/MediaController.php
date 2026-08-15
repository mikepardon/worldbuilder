<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\World;
use App\Support\WorldNav;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class MediaController extends Controller
{
    /** A single world's media library. */
    public function index(World $world)
    {
        $this->authorize('editContent', $world);

        $items = Media::query()->where('world_id', $world->id)->latest()->get();

        return Inertia::render('Media/Index', [
            'world' => WorldNav::for($world),
            'campaign' => ['id' => $world->id, 'name' => $world->name, 'cover_media_id' => $world->cover_media_id],
            'items' => $items->map(fn (Media $m) => $this->present($m))->values(),
            'limits' => [
                'maxSizeKb' => config('media.max_size'),
                'accept' => config('media.accept'),
                'disk' => config('media.disk'),
            ],
        ]);
    }

    /** Store one uploaded file. Uppy posts one request per file and expects a JSON response. */
    public function store(Request $request, World $world): JsonResponse
    {
        $this->authorize('editContent', $world);

        $accept = config('media.accept');

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:'.implode(',', $accept), 'max:'.config('media.max_size')],
            'alt' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first('file') ?: 'That file could not be uploaded.'], 422);
        }

        $user = $request->user();
        $file = $request->file('file');

        // Enforce the account's per-plan storage quota (admins are exempt, as with the world limit).
        if (! $user->canStore((int) $file->getSize())) {
            return response()->json(['message' => 'Storage limit reached — delete some media or upgrade your plan for more space.'], 422);
        }

        $disk = config('media.disk');
        $path = $file->store('media', $disk);

        $media = Media::create([
            'user_id' => Auth::id(),
            'world_id' => $world->id,
            'disk' => $disk,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'alt' => $validator->validated()['alt'] ?? null,
        ]);

        return response()->json($this->present($media), 201);
    }

    public function update(Request $request, Media $media)
    {
        $this->authorize('update', $media);

        $data = $request->validate([
            'alt' => ['nullable', 'string', 'max:255'],
        ]);

        $media->update($data);

        return back();
    }

    public function destroy(Media $media)
    {
        $this->authorize('delete', $media);

        $media->delete(); // model event removes the underlying file

        return back()->with('success', 'Media deleted.');
    }

    protected function present(Media $m): array
    {
        return [
            'id' => $m->id,
            'url' => $m->url,
            'filename' => $m->filename,
            'mime' => $m->mime,
            'size' => $m->size,
            'alt' => $m->alt,
            'world_id' => $m->world_id,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
