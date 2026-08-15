<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Session;
use App\Services\S3\MultipartUploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Signs a browser-driven multipart upload of a session recording straight to S3 (via Uppy), so
 * multi-gigabyte audio never passes through PHP. Every call is authorised to the campaign GM and every
 * key is pinned under this session's own prefix, so a signed URL can only ever write this session's
 * folder. {@see RecapController::store()} records the finished object as the session's recap.
 */
class SessionUploadController extends Controller
{
    public function __construct(private MultipartUploads $uploads) {}

    /** Begin a multipart upload; returns the object key and S3 upload id Uppy will use for the parts. */
    public function create(Request $request, Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $user = $request->user();
        if (! $user->canSpendAiCredit()) {
            return response()->json([
                'message' => 'You’re out of AI credits for today — they reset daily. Top up or upgrade for more.',
                'outOfCredits' => true,
            ], 402);
        }

        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'size' => ['nullable', 'integer', 'min:0'],
        ]);

        // Fail fast if the recording won't fit the storage quota. The client reports the size up front;
        // the finished object's real size is recorded authoritatively when the recap is stored.
        $declaredSize = (int) ($data['size'] ?? 0);
        if ($declaredSize > 0 && ! $user->canStore($declaredSize)) {
            return response()->json([
                'message' => 'Storage limit reached — this recording is larger than your remaining space. Upgrade your plan for more.',
            ], 422);
        }

        $extension = Str::lower(pathinfo($data['filename'], PATHINFO_EXTENSION));
        if (! in_array($extension, ['mp3', 'm4a', 'mp4', 'wav', 'aac', 'ogg', 'oga', 'opus', 'webm', 'flac', 'aiff', 'mov', 'mkv'], true)) {
            return response()->json(['message' => 'Upload an audio recording (mp3, m4a, wav, ogg, webm, aac, opus or flac).'], 422);
        }

        $key = $this->prefix($session).Str::uuid()->toString().'.'.$extension;
        $uploadId = $this->uploads->create($key, (string) ($data['type'] ?? ''));

        return response()->json(['key' => $key, 'uploadId' => $uploadId]);
    }

    /** Presign one part upload. */
    public function sign(Request $request, Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $data = $request->validate([
            'key' => ['required', 'string'],
            'uploadId' => ['required', 'string'],
            'partNumber' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        $this->assertOwnedKey($session, $data['key']);

        return response()->json(['url' => $this->uploads->signPart($data['key'], $data['uploadId'], $data['partNumber'])]);
    }

    /** List already-uploaded parts so an interrupted upload can resume. */
    public function list(Request $request, Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $data = $request->validate([
            'key' => ['required', 'string'],
            'uploadId' => ['required', 'string'],
        ]);
        $this->assertOwnedKey($session, $data['key']);

        return response()->json($this->uploads->listParts($data['key'], $data['uploadId']));
    }

    /** Assemble the parts into the final object. */
    public function complete(Request $request, Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $data = $request->validate([
            'key' => ['required', 'string'],
            'uploadId' => ['required', 'string'],
            'parts' => ['required', 'array', 'min:1'],
            'parts.*.PartNumber' => ['required', 'integer', 'min:1'],
            'parts.*.ETag' => ['required', 'string'],
        ]);
        $this->assertOwnedKey($session, $data['key']);

        $parts = collect($data['parts'])
            ->map(fn (array $part): array => ['PartNumber' => (int) $part['PartNumber'], 'ETag' => $part['ETag']])
            ->all();

        return response()->json(['location' => $this->uploads->complete($data['key'], $data['uploadId'], $parts)]);
    }

    /** Discard an abandoned upload. */
    public function abort(Request $request, Session $session): JsonResponse
    {
        $this->authorize('manage', $session->campaign);

        $data = $request->validate([
            'key' => ['required', 'string'],
            'uploadId' => ['required', 'string'],
        ]);
        $this->assertOwnedKey($session, $data['key']);

        $this->uploads->abort($data['key'], $data['uploadId']);

        return response()->json(['aborted' => true]);
    }

    /** The one folder this session's uploads may ever write to. */
    private function prefix(Session $session): string
    {
        return "recaps/{$session->id}/";
    }

    /** A signed URL must never be able to touch another session's (or anyone else's) objects. */
    private function assertOwnedKey(Session $session, string $key): void
    {
        abort_unless(str_starts_with($key, $this->prefix($session)), 403);
    }
}
