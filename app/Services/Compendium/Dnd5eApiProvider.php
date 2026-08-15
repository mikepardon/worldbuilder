<?php

declare(strict_types=1);

namespace App\Services\Compendium;

use App\Models\CompendiumSource;
use App\Support\Statblock;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * dnd5eapi.co provider. Its list endpoints return references ({index, name, url}) rather than full
 * records, so we fetch each detail. Shapes differ from Open5e, so each type has its own mapper.
 */
class Dnd5eApiProvider implements CompendiumProvider
{
    protected function http(): PendingRequest
    {
        // dnd5eapi resolves IPv6 first too; force IPv4 + retry, matching the Open5e client.
        return Http::acceptJson()->connectTimeout(10)->timeout(20)->retry(3, 400)->withOptions(['force_ip_resolve' => 'v4']);
    }

    public function records(CompendiumSource $source, int $maxPages): iterable
    {
        $parts = parse_url($source->api_url);
        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'www.dnd5eapi.co');

        $refs = $this->http()->get($source->api_url)->json('results') ?? [];
        $limit = $maxPages * 100; // safety cap on the fan-out of detail requests
        $count = 0;

        foreach ($refs as $ref) {
            if ($count++ >= $limit) {
                break;
            }
            $path = $ref['url'] ?? null;
            if (! $path) {
                continue;
            }
            $detail = $this->http()->get($base.$path)->json();
            if (is_array($detail) && ($detail['index'] ?? null)) {
                yield $detail;
            }
        }
    }

    /** How many detail records to fetch per chunk — kept small so each queued run stays quick. */
    private const CHUNK = 25;

    public function chunk(CompendiumSource $source, ?string $cursor): array
    {
        // The cursor is an offset into the (cheap, single-call) reference list; each chunk fetches the
        // detail record for the next slice of refs.
        $offset = max(0, (int) ($cursor ?? 0));
        $parts = parse_url($source->api_url);
        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'www.dnd5eapi.co');

        $refs = $this->http()->get($source->api_url)->json('results') ?? [];
        $records = [];
        foreach (array_slice($refs, $offset, self::CHUNK) as $ref) {
            $path = $ref['url'] ?? null;
            if (! $path) {
                continue;
            }
            $detail = $this->http()->get($base.$path)->json();
            if (is_array($detail) && ($detail['index'] ?? null)) {
                $records[] = $detail;
            }
        }

        $next = ($offset + self::CHUNK) < count($refs) ? (string) ($offset + self::CHUNK) : null;

        return ['records' => $records, 'next' => $next];
    }

    public function slug(array $raw): ?string
    {
        return $raw['index'] ?? (($raw['name'] ?? '') !== '' ? Str::slug($raw['name']) : null);
    }

    public function preview(string $itemType, array $raw): array
    {
        $name = $raw['name'] ?? 'Untitled';
        $summary = Str::limit(trim(strip_tags($this->desc($raw))), 140, '…');

        $meta = match ($itemType) {
            'monster' => trim(ucfirst((string) ($raw['size'] ?? '')).' '.($raw['type'] ?? '')).' · CR '.($raw['challenge_rating'] ?? '?'),
            'spell' => 'Level '.($raw['level'] ?? '?').' · '.($raw['school']['name'] ?? ''),
            'magicitem' => trim(($raw['equipment_category']['name'] ?? '').' · '.($raw['rarity']['name'] ?? '')),
            'equipment' => (string) ($raw['equipment_category']['name'] ?? ''),
            'race' => (string) ($raw['size'] ?? ''),
            default => Str::limit(trim(strip_tags($this->desc($raw))), 80, '…'),
        };

        return [
            'slug' => $this->slug($raw) ?? Str::slug($name),
            'name' => $name,
            'summary' => $summary,
            'meta' => trim($meta, " ·\t\n"),
        ];
    }

    public function toBlock(string $itemType, array $raw): ?array
    {
        if ($itemType !== 'monster' || ! isset($raw['strength'])) {
            return null;
        }

        $signed = fn (int $n): string => ($n >= 0 ? '+' : '').$n;
        $names = fn ($list) => is_array($list)
            ? implode(', ', array_filter(array_map(fn ($x) => is_array($x) ? ($x['name'] ?? '') : (string) $x, $list)))
            : trim((string) ($list ?? ''));
        $entries = fn ($list) => is_array($list)
            ? collect($list)->map(fn ($e) => ['name' => (string) ($e['name'] ?? ''), 'desc' => (string) ($e['desc'] ?? '')])->filter(fn ($e) => $e['name'] !== '' || $e['desc'] !== '')->values()->all()
            : [];

        // Split proficiencies into saving throws and skills ("Saving Throw: DEX", "Skill: Perception").
        $saves = [];
        $skills = [];
        foreach ($raw['proficiencies'] ?? [] as $prof) {
            $label = $prof['proficiency']['name'] ?? '';
            $value = $signed((int) ($prof['value'] ?? 0));
            if (str_starts_with($label, 'Saving Throw:')) {
                $saves[] = trim(str_replace('Saving Throw:', '', $label)).' '.$value;
            } elseif (str_starts_with($label, 'Skill:')) {
                $skills[] = trim(str_replace('Skill:', '', $label)).' '.$value;
            }
        }

        $speed = is_array($raw['speed'] ?? null)
            ? implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($raw['speed']), array_values($raw['speed'])))
            : (string) ($raw['speed'] ?? '');
        $senses = is_array($raw['senses'] ?? null)
            ? implode(', ', array_map(fn ($k, $v) => str_replace('_', ' ', $k)." {$v}", array_keys($raw['senses']), array_values($raw['senses'])))
            : (string) ($raw['senses'] ?? '');

        return array_merge(Statblock::empty(), [
            'size' => (string) ($raw['size'] ?? 'Medium'),
            'type' => (string) ($raw['type'] ?? 'humanoid'),
            'alignment' => (string) ($raw['alignment'] ?? ''),
            'ac' => (string) ($raw['armor_class'][0]['value'] ?? $raw['armor_class'] ?? ''),
            'hp' => trim(($raw['hit_points'] ?? '').' ('.($raw['hit_dice'] ?? '').')'),
            'speed' => $speed,
            'abilities' => [
                'str' => (int) ($raw['strength'] ?? 10), 'dex' => (int) ($raw['dexterity'] ?? 10),
                'con' => (int) ($raw['constitution'] ?? 10), 'int' => (int) ($raw['intelligence'] ?? 10),
                'wis' => (int) ($raw['wisdom'] ?? 10), 'cha' => (int) ($raw['charisma'] ?? 10),
            ],
            'saves' => implode(', ', $saves),
            'skills' => implode(', ', $skills),
            'vulnerabilities' => $names($raw['damage_vulnerabilities'] ?? []),
            'resistances' => $names($raw['damage_resistances'] ?? []),
            'immunities' => $names($raw['damage_immunities'] ?? []),
            'conditionImmunities' => $names($raw['condition_immunities'] ?? []),
            'senses' => $senses,
            'languages' => (string) ($raw['languages'] ?? ''),
            'cr' => (string) ($raw['challenge_rating'] ?? ''),
            'traits' => $entries($raw['special_abilities'] ?? []),
            'actions' => $entries($raw['actions'] ?? []),
            'reactions' => $entries($raw['reactions'] ?? []),
            'legendary' => $entries($raw['legendary_actions'] ?? []),
        ]);
    }

    public function toFields(string $itemType, array $raw): array
    {
        $fields = match ($itemType) {
            'spell' => [
                'level' => $this->spellLevel($raw),
                'school' => (string) ($raw['school']['name'] ?? ''),
                'casting_time' => (string) ($raw['casting_time'] ?? ''),
                'range' => (string) ($raw['range'] ?? ''),
                'components' => $this->spellComponents($raw),
                'duration' => (string) ($raw['duration'] ?? ''),
                'description' => $this->desc($raw),
                'higher_levels' => is_array($raw['higher_level'] ?? null) ? implode("\n\n", $raw['higher_level']) : '',
            ],
            'magicitem' => [
                'category' => (string) ($raw['equipment_category']['name'] ?? ''),
                'rarity' => (string) ($raw['rarity']['name'] ?? ''),
                'description' => $this->desc($raw),
            ],
            'condition' => ['description' => $this->desc($raw)],
            'equipment' => [
                'category' => (string) ($raw['equipment_category']['name'] ?? ''),
                'cost' => isset($raw['cost']) ? trim(($raw['cost']['quantity'] ?? '').' '.($raw['cost']['unit'] ?? '')) : '',
                'weight' => (string) ($raw['weight'] ?? ''),
                'damage' => isset($raw['damage']) ? trim(($raw['damage']['damage_dice'] ?? '').' '.($raw['damage']['damage_type']['name'] ?? '')) : '',
                'damage_type' => (string) ($raw['damage']['damage_type']['name'] ?? ''),
                'properties' => collect($raw['properties'] ?? [])->pluck('name')->filter()->implode(', '),
                'ac' => isset($raw['armor_class']) ? $this->armorAc($raw['armor_class']) : '',
                'strength' => filled($raw['str_minimum'] ?? null) ? 'Str '.$raw['str_minimum'] : '',
                'stealth' => ! empty($raw['stealth_disadvantage']) ? 'Disadvantage' : '',
                'description' => $this->desc($raw),
            ],
            'feat' => [
                'prerequisite' => is_array($raw['prerequisites'] ?? null) ? collect($raw['prerequisites'])->pluck('name')->filter()->implode(', ') : '',
                'description' => $this->desc($raw),
            ],
            'race' => [
                'size' => (string) ($raw['size'] ?? ''),
                'speed' => filled($raw['speed'] ?? null) ? "{$raw['speed']} ft." : '',
                'ability_bonuses' => collect($raw['ability_bonuses'] ?? [])->map(fn ($b) => '+'.($b['bonus'] ?? 0).' '.($b['ability_score']['name'] ?? ''))->filter()->implode(', '),
                'description' => trim((string) ($raw['size_description'] ?? '')."\n\n".(string) ($raw['alignment'] ?? '')),
            ],
            default => [],
        };

        return array_filter($fields, static fn ($value) => trim((string) $value) !== '');
    }

    /** Build an armor "AC" string from dnd5eapi's {base, dex_bonus, max_bonus}. */
    protected function armorAc(array $ac): string
    {
        $base = (string) ($ac['base'] ?? '');
        if (empty($ac['dex_bonus'])) {
            return $base;
        }
        $max = isset($ac['max_bonus']) ? " (max {$ac['max_bonus']})" : '';

        return "{$base} + Dex modifier{$max}";
    }

    /** dnd5eapi desc is an array of paragraphs. */
    protected function desc(array $raw): string
    {
        $desc = $raw['desc'] ?? '';

        return is_array($desc) ? trim(implode("\n\n", array_map('strval', $desc))) : trim((string) $desc);
    }

    protected function spellLevel(array $raw): string
    {
        $level = is_numeric($raw['level'] ?? null) ? (int) $raw['level'] : null;
        if ($level === 0) {
            return 'Cantrip';
        }
        $ordinals = ['1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th'];

        return ($level !== null && $level >= 1 && $level <= 9) ? $ordinals[$level - 1] : '';
    }

    protected function spellComponents(array $raw): string
    {
        $components = is_array($raw['components'] ?? null) ? implode(', ', $raw['components']) : (string) ($raw['components'] ?? '');
        $material = trim((string) ($raw['material'] ?? ''));

        return $material !== '' ? trim("{$components} ({$material})") : trim($components);
    }
}
