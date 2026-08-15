<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CampaignCompendiumItem;
use App\Models\CompendiumImport;
use App\Services\DdbClient;
use App\Services\DdbImageStore;
use App\Support\Ddb;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs a D&D Beyond import off the web request so large accounts don't hit the 30s execution limit.
 * Fetches the selected types, downloads/dedupes their art, and stores GM-only compendium entries,
 * streaming progress into the {@see CompendiumImport} record the import page polls.
 */
class RunDdbImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A partial import must not be silently re-run and duplicated. */
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public CompendiumImport $import) {}

    public function handle(DdbClient $ddb, DdbImageStore $images): void
    {
        $import = $this->import->fresh();
        if ($import === null || $import->isFinished()) {
            return;
        }

        $world = $import->world;

        try {
            $import->markRunning();

            $cobalt = (string) ($world->ddbCobalt() ?? '');
            if ($cobalt === '') {
                $import->markFailed('No D&D Beyond token is saved for this world. Add one on the keys page.');

                return;
            }

            $import->pushLog('Authenticating with D&D Beyond…');
            $bearer = $ddb->token($cobalt);
            if ($bearer === null) {
                $import->markFailed('The saved Cobalt token was rejected. Save a fresh token and try again.');

                return;
            }

            $records = $this->collect($ddb, $bearer, $import);
            if ($records === []) {
                $import->markFailed('Nothing came back for the selected types — the token may lack access, or the D&D Beyond API changed.');

                return;
            }

            $mediaByKey = $this->resolveImages($records, $images, $import);
            $this->store($world->id, $import, $records, $mediaByKey);

            $import->markCompleted();
        } catch (Throwable $e) {
            // Record a safe, user-facing status and re-throw so failed_jobs keeps the full detail.
            $import->markFailed('Import failed unexpectedly ('.class_basename($e).'). Please try again.');

            throw $e;
        }
    }

    /**
     * Fetch and map every selected type into a uniform record shape, logging progress as it goes.
     *
     * @return list<array{item_type: string, name: string, summary: string, fields: array<string, mixed>, document: string, image: array{url: string, key: string}}>
     */
    private function collect(DdbClient $ddb, string $bearer, CompendiumImport $import): array
    {
        $types = $import->types ?? [];
        $campaignId = $import->ddb_campaign_id;
        $records = [];

        if (in_array('monster', $types, true)) {
            $import->pushLog('Fetching monsters…');
            $lookups = $ddb->lookups($bearer);
            $raws = $ddb->monsters($bearer, $import->homebrew);
            $import->pushLog(count($raws).' monsters found.');
            foreach ($raws as $raw) {
                $mapped = Ddb::toItem($raw, $lookups);
                if ($mapped['name'] === '') {
                    continue;
                }
                $records[] = [
                    'item_type' => 'monster',
                    'name' => $mapped['name'],
                    'summary' => $mapped['summary'],
                    'fields' => ['block' => $mapped['block']],
                    'document' => $mapped['document'],
                    'image' => $mapped['image'],
                ];
            }
        }

        if (in_array('item', $types, true)) {
            $import->pushLog('Fetching items…');
            $raws = $ddb->items($bearer, $campaignId);
            $import->pushLog(count($raws).' items found.');
            foreach ($raws as $raw) {
                $mapped = Ddb::itemToItem($raw);
                if ($mapped['name'] === '' || $mapped['name'] === 'Unnamed') {
                    continue;
                }
                $records[] = $mapped;
            }
        }

        if (in_array('spell', $types, true)) {
            $import->pushLog('Fetching spells…');
            $raws = $ddb->spells($bearer, $campaignId);
            $import->pushLog(count($raws).' spells found.');
            foreach ($raws as $raw) {
                $mapped = Ddb::spellToItem($raw);
                if ($mapped['name'] === '' || $mapped['name'] === 'Unnamed') {
                    continue;
                }
                $records[] = $mapped;
            }
        }

        return $records;
    }

    /**
     * Download and dedupe every distinct image up front (outside any transaction), returning a
     * key→media-id map. Done before inserting so the DB transaction never waits on HTTP.
     *
     * @param  list<array<string, mixed>>  $records
     * @return array<string, int>
     */
    private function resolveImages(array $records, DdbImageStore $images, CompendiumImport $import): array
    {
        $keys = [];
        foreach ($records as $record) {
            $key = (string) ($record['image']['key'] ?? '');
            if ($key !== '') {
                $keys[$key] = $record['image'];
            }
        }

        if ($keys === []) {
            return [];
        }

        $import->pushLog('Fetching '.count($keys).' images…');
        $map = [];
        $done = 0;
        foreach ($keys as $key => $image) {
            $mediaId = $images->resolve($image);
            if ($mediaId !== null) {
                $map[$key] = $mediaId;
            }
            $done++;
            if ($done % 25 === 0) {
                $import->update(['images' => count($map), 'message' => "Fetching images… {$done}/".count($keys)]);
            }
        }

        $import->update(['images' => count($map)]);

        return $map;
    }

    /**
     * Insert the mapped records as GM-only compendium entries in one transaction, attaching the
     * pre-resolved shared images.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, int>  $mediaByKey
     */
    private function store(int $worldId, CompendiumImport $import, array $records, array $mediaByKey): void
    {
        $import->pushLog('Saving '.count($records).' entries…');
        $userId = $import->user_id;
        $counts = [];

        DB::transaction(function () use ($worldId, $userId, $records, $mediaByKey, &$counts): void {
            foreach ($records as $record) {
                $key = (string) ($record['image']['key'] ?? '');
                CampaignCompendiumItem::create([
                    'world_id' => $worldId,
                    'user_id' => $userId,
                    'item_type' => $record['item_type'],
                    'slug' => Str::slug($record['name']).'-'.Str::lower(Str::random(4)),
                    'name' => $record['name'],
                    'summary' => $record['summary'],
                    'document' => $record['document'],
                    'fields' => $record['fields'],
                    'image_media_id' => $mediaByKey[$key] ?? null,
                    'provider' => 'custom', // GM-owned import; no upstream to sync
                    'origin' => 'ddb',      // shown in the compendium's Source column
                    'is_private' => true,   // GM-only until the GM reveals it
                    'is_active' => true,
                ]);
                $counts[$record['item_type']] = ($counts[$record['item_type']] ?? 0) + 1;
            }
        });

        $import->update([
            'counts' => $counts,
            'imported' => count($records),
            'processed' => count($records),
        ]);
    }
}
