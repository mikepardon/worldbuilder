<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Thin client over the Open5e SRD API (https://api.open5e.com). */
class Open5eClient
{
    protected const BASE = 'https://api.open5e.com/v1';

    /**
     * A resilient request for Open5e. Open5e's DNS returns IPv6 (AAAA) records first; on hosts without
     * a working IPv6 route cURL stalls and reports "could not resolve host"/timeout, so we force IPv4.
     * Retries smooth over transient DNS/connection blips; a separate connect timeout fails fast.
     */
    protected function http(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout(10)
            ->timeout(20)
            ->retry(3, 400)
            ->withOptions(['force_ip_resolve' => 'v4']);
    }

    /** Our compendium type → Open5e endpoint. */
    protected const ENDPOINTS = [
        'monster' => 'monsters',
        'spell' => 'spells',
        'magicitem' => 'magicitems',
        'condition' => 'conditions',
        'race' => 'races',
        'feat' => 'feats',
        // Note: "equipment" isn't 1:1 with an Open5e endpoint — it's fed by the weapons + armor
        // endpoints (see CompendiumSourceSeeder), so it is intentionally absent here.
    ];

    /** Endpoint slugs we know how to import, keyed by our compendium item type. */
    public static function endpoints(): array
    {
        return self::ENDPOINTS;
    }

    /**
     * Surface an unexpected non-ok response from Open5e to Sentry. A fetch degrades to empty results on
     * failure so an import can complete, but that silently truncates the import — surface it so a broken
     * endpoint or exhausted retries leave a trace rather than a quietly partial library.
     */
    protected function reportFailedResponse(Response $response, string $context): void
    {
        report(new RuntimeException("Open5e {$context} request failed ({$response->status()})."));
    }

    /** Fetch one page of a paginated Open5e listing by absolute URL. */
    public function page(string $url): array
    {
        $response = $this->http()->get($url);

        if (! $response->ok()) {
            $this->reportFailedResponse($response, 'page');

            return ['results' => [], 'next' => null, 'count' => 0];
        }

        return [
            'results' => $response->json('results') ?? [],
            'next' => $response->json('next'),
            'count' => $response->json('count') ?? 0,
        ];
    }

    /** Search a type by name; returns the raw Open5e result records (max $limit). */
    public function search(string $type, string $query, int $limit = 20): array
    {
        $endpoint = self::ENDPOINTS[$type] ?? null;
        if (! $endpoint) {
            return [];
        }

        $response = $this->http()->get(self::BASE."/{$endpoint}/", [
            'search' => $query,
            'limit' => $limit,
        ]);
        if (! $response->ok()) {
            $this->reportFailedResponse($response, 'search');

            return [];
        }

        return $response->json('results') ?? [];
    }

    /**
     * Map a raw Open5e record into our CompendiumFields structured values for a non-monster type, so
     * the importer can render a proper document (monsters use Statblock::fromOpen5e instead).
     *
     * @return array<string, string>
     */
    public static function toFields(string $type, array $raw): array
    {
        $text = static fn ($value): string => is_array($value)
            ? trim(implode("\n\n", array_filter(array_map('strval', $value))))
            : trim((string) ($value ?? ''));

        $fields = match ($type) {
            'spell' => [
                'level' => self::spellLevel($raw),
                'school' => ucfirst($text($raw['school'] ?? '')),
                'casting_time' => $text($raw['casting_time'] ?? ''),
                'range' => $text($raw['range'] ?? ''),
                'components' => self::spellComponents($raw),
                'duration' => $text($raw['duration'] ?? ''),
                'description' => $text($raw['desc'] ?? ''),
                'higher_levels' => $text($raw['higher_level'] ?? ''),
            ],
            // Handles both Open5e weapon records (damage_dice) and armor records (ac_string); the
            // irrelevant keys come back empty and are stripped below.
            'equipment' => [
                'category' => $text($raw['category'] ?? ''),
                'cost' => $text($raw['cost'] ?? ''),
                'weight' => $text($raw['weight'] ?? ''),
                'damage' => $text($raw['damage_dice'] ?? ''),
                'damage_type' => $text($raw['damage_type'] ?? ''),
                'properties' => is_array($raw['properties'] ?? null) ? implode(', ', array_map('strval', $raw['properties'])) : $text($raw['properties'] ?? ''),
                'ac' => $text($raw['ac_string'] ?? $raw['base_ac'] ?? ''),
                'strength' => filled($raw['strength_requirement'] ?? null) ? 'Str '.$raw['strength_requirement'] : '',
                'stealth' => ! empty($raw['stealth_disadvantage']) ? 'Disadvantage' : '',
                'description' => $text($raw['desc'] ?? ''),
            ],
            'magicitem' => [
                'category' => $text($raw['type'] ?? ''),
                'rarity' => ucfirst($text($raw['rarity'] ?? '')),
                'attunement' => ! empty($raw['requires_attunement']) ? 'Yes' : 'No',
                'description' => $text($raw['desc'] ?? ''),
            ],
            'feat' => [
                'prerequisite' => $text($raw['prerequisite'] ?? ''),
                'description' => $text($raw['desc'] ?? ''),
            ],
            'condition' => [
                'description' => $text($raw['desc'] ?? ''),
            ],
            'race' => [
                'size' => $text($raw['size_raw'] ?? $raw['size'] ?? ''),
                'speed' => $text($raw['speed_desc'] ?? (is_array($raw['speed'] ?? null) ? (($raw['speed']['walk'] ?? '').' ft.') : ($raw['speed'] ?? ''))),
                'ability_bonuses' => $text($raw['asi_desc'] ?? ''),
                'description' => $text([$raw['desc'] ?? '', $raw['traits'] ?? '']),
            ],
            default => [],
        };

        return array_filter($fields, static fn ($value) => $value !== '');
    }

    protected static function spellLevel(array $raw): string
    {
        $level = is_numeric($raw['level_int'] ?? null) ? (int) $raw['level_int'] : null;
        if ($level === 0) {
            return 'Cantrip';
        }
        $ordinals = ['1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th'];
        if ($level !== null && $level >= 1 && $level <= 9) {
            return $ordinals[$level - 1];
        }

        return trim((string) ($raw['level'] ?? ''));
    }

    protected static function spellComponents(array $raw): string
    {
        $components = trim((string) ($raw['components'] ?? ''));
        if ($components === '') {
            $parts = [];
            if (! empty($raw['requires_verbal_components'])) {
                $parts[] = 'V';
            }
            if (! empty($raw['requires_somatic_components'])) {
                $parts[] = 'S';
            }
            if (! empty($raw['requires_material_components'])) {
                $parts[] = 'M';
            }
            $components = implode(', ', $parts);
        }
        $material = trim((string) ($raw['material'] ?? ''));

        return $material !== '' ? trim("{$components} ({$material})") : $components;
    }

    /** Condense a raw Open5e record into a pick-list preview: name, slug, blurb and key facets. */
    public static function preview(string $type, array $raw): array
    {
        $name = self::text($raw['name'] ?? '') ?: 'Untitled';
        $slug = is_string($raw['slug'] ?? null) ? $raw['slug'] : Str::slug($name);
        $summary = Str::limit(trim(strip_tags(self::text($raw['desc'] ?? ''))), 140, '…');

        $meta = match ($type) {
            'monster' => trim(ucfirst(self::text($raw['size'] ?? '')).' '.self::text($raw['type'] ?? '')).' · CR '.self::text($raw['challenge_rating'] ?? '?'),
            'spell' => 'Level '.self::text($raw['level_int'] ?? $raw['level'] ?? '?').' · '.ucfirst(self::text($raw['school'] ?? '')),
            'magicitem' => trim(self::text($raw['type'] ?? '').' · '.ucfirst(self::text($raw['rarity'] ?? ''))),
            'equipment' => trim(self::text($raw['category'] ?? '').' · '.self::text($raw['damage_dice'] ?? $raw['ac_string'] ?? $raw['base_ac'] ?? '')),
            'race' => self::text($raw['size'] ?? ''),
            'feat' => Str::limit(trim(strip_tags(self::text($raw['prerequisite'] ?? $raw['desc'] ?? ''))), 80, '…'),
            'condition' => Str::limit(trim(strip_tags(self::text($raw['desc'] ?? ''))), 80, '…'),
            default => '',
        };

        return [
            'slug' => $slug,
            'name' => $name,
            'summary' => $summary,
            'meta' => trim($meta, " ·\t\n"),
        ];
    }

    /**
     * Coerce a raw Open5e field into a string. Some records deliver fields (notably `desc`) as an array
     * of paragraphs; casting one straight to string throws "Array to string conversion", so flatten it.
     */
    private static function text(mixed $value): string
    {
        if (is_array($value)) {
            return trim(implode("\n\n", array_filter(array_map(
                fn ($item) => is_scalar($item) ? (string) $item : '',
                $value,
            ))));
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
