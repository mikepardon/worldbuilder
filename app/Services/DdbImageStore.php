<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Media;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves an externally-sourced image (a {url, key} pair from the DDB mapper) into a shared
 * {@see Media} record, downloading it once. The dedup key means the same creature/item art imported
 * by many worlds is fetched and stored a single time (campaign_id null = shared pool).
 */
class DdbImageStore
{
    /** @var array<string, string> */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
        'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif',
    ];

    /**
     * Return the shared Media id for an image spec, fetching and storing it on first sight. Null when
     * there is no image or the download fails (a missing image must never abort an import).
     *
     * @param  array{url?: string, key?: string}  $image
     */
    public function resolve(array $image): ?int
    {
        $url = trim((string) ($image['url'] ?? ''));
        $key = trim((string) ($image['key'] ?? ''));
        if ($url === '' || $key === '') {
            return null;
        }

        $existing = Media::where('external_key', $key)->first();
        if ($existing !== null) {
            return (int) $existing->id;
        }

        $response = Http::connectTimeout(10)->timeout(30)->retry(2, 500)
            ->withOptions(['force_ip_resolve' => 'v4'])->get($url);
        if (! $response->ok()) {
            return null;
        }

        $bytes = $response->body();
        if ($bytes === '') {
            return null;
        }

        $mime = trim(explode(';', (string) $response->header('Content-Type'))[0]);
        $extension = self::EXTENSIONS[mb_strtolower($mime)] ?? 'img';
        $disk = (string) config('media.disk');
        $path = "ddb/{$key}.{$extension}";

        Storage::disk($disk)->put($path, $bytes);

        // firstOrCreate guards the unique key against a concurrent import resolving the same image.
        $media = Media::firstOrCreate(['external_key' => $key], [
            'user_id' => null,       // shared pool — outlives any single importer
            'campaign_id' => null,   // not owned by one world
            'disk' => $disk,
            'path' => $path,
            'filename' => "{$key}.{$extension}",
            'mime' => $mime !== '' ? $mime : null,
            'size' => strlen($bytes),
        ]);

        return (int) $media->id;
    }
}
