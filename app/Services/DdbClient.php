<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Ddb;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A minimal in-app "ddb-proxy": talks to D&D Beyond's services directly (server-side, so no CORS)
 * using the GM's Cobalt session token. Mirrors mrprimate/ddb-proxy's flow — exchange the Cobalt
 * cookie for a short-lived bearer, then call the content services with it.
 *
 * NOTE: DDB has no public API; these endpoints are unofficial and change over time, and content is
 * only ever the GM's own (DDB gates access by the token). Import lands in the GM's world, never the
 * shared library.
 */
class DdbClient
{
    protected const AUTH_URL = 'https://auth-service.dndbeyond.com/v1/cobalt-token';

    protected const CONFIG_URL = 'https://www.dndbeyond.com/api/config/json';

    protected const MONSTER_URL = 'https://monster-service.dndbeyond.com/v1/Monster';

    protected const ITEMS_URL = 'https://character-service.dndbeyond.com/character/v5/game-data/items';

    protected const SPELLS_URL = 'https://character-service.dndbeyond.com/character/v5/game-data/spells';

    protected const CAMPAIGNS_URL = 'https://www.dndbeyond.com/api/campaign/stt/user-campaigns';

    protected const CHARACTER_URL = 'https://character-service.dndbeyond.com/character/v5/character/';

    /**
     * DDB has no "all spells" endpoint — spells are served per class. Iterating the base spellcasting
     * classes at level 20 yields every class spell (plus the account's homebrew that attaches to them).
     * These class ids are long-standing DDB constants. Subclass casters (Eldritch Knight, Arcane
     * Trickster) draw from the Wizard list, so the base casters cover their spell definitions too.
     *
     * @var array<string, int>
     */
    protected const SPELLCASTING_CLASS_IDS = [
        'Bard' => 1, 'Cleric' => 2, 'Druid' => 3, 'Paladin' => 6, 'Ranger' => 7,
        'Sorcerer' => 11, 'Warlock' => 12, 'Wizard' => 10, 'Artificer' => 252717,
    ];

    protected function http(): PendingRequest
    {
        return Http::acceptJson()->connectTimeout(10)->timeout(30)->retry(2, 500)->withOptions(['force_ip_resolve' => 'v4']);
    }

    /**
     * Surface an unexpected non-ok response from DDB to Sentry. These endpoints degrade to empty/partial
     * results on failure so an import can continue, but a silent truncation (a page that 5xx'd, a token that
     * expired mid-run) would otherwise leave only a short result set with no trace of what went wrong.
     */
    protected function reportFailedResponse(Response $response, string $context): void
    {
        report(new RuntimeException("D&D Beyond {$context} request failed ({$response->status()})."));
    }

    /**
     * A single D&D Beyond character's public record (name, avatar, HP, AC, stats). This endpoint needs
     * no auth for characters set to public — the same one Beyond20 and ddb-proxy read. Returns the raw
     * `data` object, or null when the character is missing or private.
     *
     * @return array<string, mixed>|null
     */
    public function character(string $id): ?array
    {
        try {
            $response = $this->http()->get(self::CHARACTER_URL.$id, ['includeCustomItems' => 'true']);
        } catch (ConnectionException $exception) {
            // A genuine DDB outage/timeout, not a private character — surface it, but let the caller treat
            // this character as unreadable rather than aborting the whole batch import.
            report($exception);

            return null;
        } catch (RequestException) {
            // A 4xx here means the character is private/unshared — expected and unremarkable.
            return null;
        }

        if (! $response->ok()) {
            // A 4xx is the private/unshared case (expected); a 5xx is a DDB-side failure worth surfacing.
            if ($response->serverError()) {
                $this->reportFailedResponse($response, 'character');
            }

            return null;
        }

        $data = $response->json('data');

        return is_array($data) ? $data : null;
    }

    /** Exchange a Cobalt session token for a short-lived bearer token. Null when the cobalt is invalid. */
    public function token(string $cobalt): ?string
    {
        $response = $this->http()->withHeaders(['Cookie' => "CobaltSession={$cobalt}"])->post(self::AUTH_URL);
        if (! $response->ok()) {
            // A 4xx means the pasted Cobalt session is invalid/expired — an expected user error, not
            // something to alert on. A 5xx is a DDB auth-service outage worth surfacing.
            if ($response->serverError()) {
                $this->reportFailedResponse($response, 'token');
            }

            return null;
        }

        return $response->json('token') ?: null;
    }

    /**
     * DDB's game-data config, reduced to id→name lookups per collection (sizes, monsterTypes,
     * alignments, challengeRatings, movements, senses, damageTypes, conditions, stats).
     *
     * @return array<string, array<int, string>>
     */
    public function lookups(string $bearer): array
    {
        $response = $this->http()->withToken($bearer)->get(self::CONFIG_URL);
        if (! $response->ok()) {
            $this->reportFailedResponse($response, 'lookups');
        }
        $config = $response->ok() ? ($response->json() ?? []) : [];

        $index = static function (array $rows, string $valueKey = 'name'): array {
            $out = [];
            foreach ($rows as $row) {
                if (isset($row['id'])) {
                    $out[(int) $row['id']] = (string) ($row[$valueKey] ?? '');
                }
            }

            return $out;
        };

        return [
            'sizes' => $index($config['sizes'] ?? []),
            'monsterTypes' => $index($config['monsterTypes'] ?? []),
            'alignments' => $index($config['alignments'] ?? []),
            'challengeRatings' => $index($config['challengeRatings'] ?? [], 'value'),
            'movements' => $index($config['movements'] ?? []),
            'senses' => $index($config['senses'] ?? []),
            'damageTypes' => $index($config['damageTypes'] ?? []),
            'conditions' => $index($config['conditions'] ?? []),
        ];
    }

    /**
     * Every monster the account can access (paginated by skip/take). Returns raw DDB monster records.
     *
     * @return list<array<string, mixed>>
     */
    public function monsters(string $bearer, bool $homebrew = true, int $maxPages = 60): array
    {
        $out = [];
        $skip = 0;
        $take = 100;
        $pages = 0;

        do {
            $response = $this->http()->withToken($bearer)->get(self::MONSTER_URL, [
                'skip' => $skip,
                'take' => $take,
                'showHomebrew' => $homebrew ? 'true' : 'false',
            ]);
            if (! $response->ok()) {
                $this->reportFailedResponse($response, 'monsters');
                break;
            }
            $data = $response->json('data') ?? [];
            foreach ($data as $monster) {
                $out[] = $monster;
            }
            $total = (int) ($response->json('pagination.total') ?? 0);
            $skip += $take;
            $pages++;
        } while (count($data) === $take && $skip < $total && $pages < $maxPages);

        return $out;
    }

    /**
     * The GM's D&D Beyond campaigns, so content shared through one can be pulled. Returns a normalised
     * list of {id, name}.
     *
     * @return list<array{id: string, name: string}>
     */
    public function campaigns(string $bearer): array
    {
        $response = $this->http()->withToken($bearer)->get(self::CAMPAIGNS_URL);
        if (! $response->ok()) {
            $this->reportFailedResponse($response, 'campaigns');

            return [];
        }

        $rows = $response->json('data') ?? $response->json() ?? [];
        $out = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if ($id === null) {
                continue;
            }
            $out[] = ['id' => (string) $id, 'name' => (string) ($row['name'] ?? "Campaign {$id}")];
        }

        return $out;
    }

    /**
     * The characters shared into one of the GM's D&D Beyond campaigns (via the same STT endpoint the
     * campaign list uses). Returns a normalised list of {id, name, avatar}. Full stats are then pulled
     * per character via {@see character()}.
     *
     * @return list<array{id: string, name: string, avatar: string}>
     */
    public function campaignCharacters(string $bearer, string $campaignId): array
    {
        $response = $this->http()->withToken($bearer)->get(self::CAMPAIGNS_URL);
        if (! $response->ok()) {
            $this->reportFailedResponse($response, 'campaignCharacters');

            return [];
        }

        $out = [];
        foreach (($response->json('data') ?? $response->json() ?? []) as $campaign) {
            if ((string) ($campaign['id'] ?? '') !== $campaignId) {
                continue;
            }
            foreach ($campaign['characters'] ?? [] as $character) {
                $id = $character['characterId'] ?? $character['id'] ?? null;
                if ($id === null) {
                    continue;
                }
                $out[] = [
                    'id' => (string) $id,
                    'name' => (string) ($character['characterName'] ?? $character['name'] ?? 'Character'),
                    'avatar' => (string) ($character['avatarUrl'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * Every item the account can access (magic items and mundane gear in one call). Returns raw DDB
     * item records; {@see Ddb::itemToItem} splits them into magic items vs equipment.
     *
     * @return list<array<string, mixed>>
     */
    public function items(string $bearer, ?string $campaignId = null): array
    {
        $query = ['sharingSetting' => 2];
        if (filled($campaignId)) {
            $query['campaignId'] = $campaignId;
        }

        $response = $this->http()->withToken($bearer)->get(self::ITEMS_URL, $query);
        if (! $response->ok()) {
            $this->reportFailedResponse($response, 'items');

            return [];
        }

        return array_values($response->json('data') ?? []);
    }

    /**
     * Every spell across the base spellcasting classes, de-duplicated by name (a spell shared by
     * several classes is returned once). Returns raw DDB spell records.
     *
     * @return list<array<string, mixed>>
     */
    public function spells(string $bearer, ?string $campaignId = null): array
    {
        $out = [];
        $seen = [];

        foreach (self::SPELLCASTING_CLASS_IDS as $classId) {
            $query = ['classId' => $classId, 'classLevel' => 20, 'sharingSetting' => 2];
            if (filled($campaignId)) {
                $query['campaignId'] = $campaignId;
            }

            $response = $this->http()->withToken($bearer)->get(self::SPELLS_URL, $query);
            if (! $response->ok()) {
                $this->reportFailedResponse($response, 'spells');
                continue;
            }

            foreach ($response->json('data') ?? [] as $spell) {
                $name = mb_strtolower(trim((string) ($spell['definition']['name'] ?? $spell['name'] ?? '')));
                if ($name === '' || isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $out[] = $spell;
            }
        }

        return $out;
    }
}
