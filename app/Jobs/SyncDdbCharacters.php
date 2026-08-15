<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Character;
use App\Services\DdbClient;
use App\Services\DdbImageStore;
use App\Support\Ddb;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Pull a linked D&D Beyond campaign's characters into the roster, upserting by DDB id. A character that
 * can't be read (private / not shared) is imported as a name+avatar stub and flagged `ddb_unreadable`
 * so the GM can ask that player to share it.
 */
class SyncDdbCharacters implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $campaignId) {}

    public function handle(DdbClient $ddb, DdbImageStore $images): void
    {
        $campaign = Campaign::find($this->campaignId);
        if ($campaign === null) {
            return;
        }

        $world = $campaign->world;
        if (blank($world->ddbCobalt()) || blank($world->ddb_campaign_id)) {
            return;
        }

        $bearer = $ddb->token($world->ddbCobalt());
        if ($bearer === null) {
            return;
        }

        // Resolve every character (HTTP) before touching the database, so the write stays a short transaction.
        $records = [];
        foreach ($ddb->campaignCharacters($bearer, (string) $world->ddb_campaign_id) as $entry) {
            $raw = $ddb->character($entry['id']);
            $profile = $raw !== null ? Ddb::characterProfile($raw, $entry['id']) : null;

            $imageSpec = $profile['image'] ?? ($entry['avatar'] !== ''
                ? ['url' => $entry['avatar'], 'key' => 'ddb-char-'.$entry['id'].'-'.sha1($entry['avatar'])]
                : ['url' => '', 'key' => '']);
            $imageMediaId = $images->resolve($imageSpec);

            $attributes = [
                'name' => $profile['name'] ?? $entry['name'],
                'ddb_url' => 'https://www.dndbeyond.com/characters/'.$entry['id'],
                'ddb_unreadable' => $profile === null,
            ];
            if ($imageMediaId !== null) {
                $attributes['image_media_id'] = $imageMediaId;
            }
            if ($profile !== null) {
                $attributes += [
                    'level' => $profile['level'], 'class' => $profile['class'], 'race' => $profile['race'],
                    'speed' => $profile['speed'], 'passive_perception' => $profile['passive_perception'],
                    'ac' => $profile['ac'], 'hp' => $profile['hp'], 'max_hp' => $profile['max_hp'],
                    'stats' => $profile['stats'], 'sheet' => Ddb::characterSheet($raw),
                ];
            }

            $records[] = ['ddb_character_id' => $entry['id'], 'attributes' => $attributes];
        }

        DB::transaction(function () use ($campaign, $records): void {
            foreach ($records as $record) {
                Character::updateOrCreate(
                    ['campaign_id' => $campaign->id, 'ddb_character_id' => $record['ddb_character_id']],
                    $record['attributes'],
                );
            }
        });
    }
}
