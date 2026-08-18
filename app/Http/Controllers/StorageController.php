<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Recap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A player's storage breakdown: what their account is storing (session-recording audio, uploaded media,
 * and their avatar), how it counts against the plan quota, and controls to reclaim space. Deleting a
 * recording here drops only its source audio — the transcribed recap is kept (see {@see Recap::purgeAudio()}).
 */
class StorageController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $recordingsBytes = (int) Recap::where('user_id', $user->id)->sum('size_bytes');
        $mediaBytes = (int) Media::where('user_id', $user->id)->sum('size');
        $avatarBytes = (int) $user->avatar_size;

        $used = $user->storageUsedBytes();
        $limit = $user->storageLimitBytes();

        return Inertia::render('Storage/Index', [
            'usage' => [
                'used_bytes' => $used,
                'limit_bytes' => $limit,
                'used_display' => $this->sizeDisplay($used),
                'limit_display' => $this->sizeDisplay($limit),
                'percent' => $limit > 0 ? min(100, (int) round($used / $limit * 100)) : 0,
                'over_limit' => $used > $limit,
                'is_admin' => $user->isAdmin(),
            ],
            'categories' => [
                ['key' => 'recordings', 'label' => 'Recordings', 'bytes' => $recordingsBytes, 'display' => $this->sizeDisplay($recordingsBytes)],
                ['key' => 'media', 'label' => 'Media & images', 'bytes' => $mediaBytes, 'display' => $this->sizeDisplay($mediaBytes)],
                ['key' => 'avatar', 'label' => 'Avatar', 'bytes' => $avatarBytes, 'display' => $this->sizeDisplay($avatarBytes)],
            ],
            'recordings' => $this->recordings($user->id),
            'media' => $this->media($user->id),
            'avatar' => [
                'size_bytes' => $avatarBytes,
                'size_display' => $this->sizeDisplay($avatarBytes),
                'has_avatar' => $avatarBytes > 0,
            ],
        ]);
    }

    /** Delete a recording's source audio to reclaim storage, keeping the transcribed recap intact. */
    public function destroyRecording(Request $request, Recap $recap): RedirectResponse
    {
        // The owner (uploader) manages their own storage; the audio counts against their quota, not the campaign's.
        abort_unless($recap->user_id === $request->user()->id, 403);

        $recap->purgeAudio();

        return back()->with('success', 'Recording audio deleted — the recap was kept.');
    }

    /**
     * The account's stored recordings that still have audio, largest first.
     *
     * @return list<array<string, mixed>>
     */
    private function recordings(int $userId): array
    {
        return Recap::where('user_id', $userId)
            ->where('size_bytes', '>', 0)
            ->with(['session:id,title,campaign_id', 'session.campaign:id,name'])
            ->orderByDesc('size_bytes')
            ->get(['id', 'session_id', 'size_bytes', 'duration_seconds', 'original_name', 'status', 'created_at'])
            ->map(fn (Recap $recap): array => [
                'id' => $recap->id,
                'title' => $recap->session?->title ?? ($recap->original_name ?? 'Session recording'),
                'campaign' => $recap->session?->campaign?->name,
                'size_bytes' => $recap->size_bytes,
                'size_display' => $this->sizeDisplay($recap->size_bytes),
                'duration' => $this->durationLabel((int) $recap->duration_seconds),
                'recap_kept' => $recap->status === 'done',
            ])
            ->values()->all();
    }

    /**
     * The account's uploaded media, largest first.
     *
     * @return list<array<string, mixed>>
     */
    private function media(int $userId): array
    {
        return Media::where('user_id', $userId)
            ->with('world:id,name')
            ->orderByDesc('size')
            ->get(['id', 'world_id', 'disk', 'path', 'filename', 'mime', 'size'])
            ->map(fn (Media $item): array => [
                'id' => $item->id,
                'filename' => $item->filename,
                'world' => $item->world?->name,
                'mime' => $item->mime,
                'size_bytes' => (int) $item->size,
                'size_display' => $this->sizeDisplay((int) $item->size),
                'url' => $item->url,
            ])
            ->values()->all();
    }

    /** Human-readable size: B under a KB, KB under a MB, else MB/GB to one decimal (trailing zero trimmed). */
    private function sizeDisplay(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 3 => rtrim(rtrim(number_format($bytes / 1024 ** 3, 1), '0'), '.').' GB',
            $bytes >= 1024 ** 2 => rtrim(rtrim(number_format($bytes / 1024 ** 2, 1), '0'), '.').' MB',
            $bytes >= 1024 => (int) round($bytes / 1024).' KB',
            default => "{$bytes} B",
        };
    }

    /** A short "N.N h" / "N min" label for an audio length in seconds. */
    private function durationLabel(int $seconds): string
    {
        return match (true) {
            $seconds >= 3600 => number_format($seconds / 3600, 1).' h',
            default => max(1, (int) round($seconds / 60)).' min',
        };
    }
}
