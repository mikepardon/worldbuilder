<?php

namespace App\Support;

/**
 * D&D 5e stat block helpers — a PHP port of the original TS logic. A stat block is stored as an
 * associative array under a compendium item's `fields['block']`. Open5e monster payloads (kept in
 * `data`) can be parsed into that structure, and any block rendered to homebrew markdown.
 */
class Statblock
{
    public const ABILITIES = ['str', 'dex', 'con', 'int', 'wis', 'cha'];

    public static function empty(): array
    {
        return [
            'size' => 'Medium', 'type' => 'humanoid', 'alignment' => 'neutral',
            'ac' => '13', 'hp' => '22 (4d8 + 4)', 'speed' => '30 ft.',
            'abilities' => ['str' => 10, 'dex' => 10, 'con' => 10, 'int' => 10, 'wis' => 10, 'cha' => 10],
            'saves' => '', 'skills' => '', 'vulnerabilities' => '', 'resistances' => '',
            'immunities' => '', 'conditionImmunities' => '', 'senses' => '', 'languages' => 'Common',
            'legendaryResistance' => '',
            'cr' => '1', 'traits' => [], 'actions' => [], 'bonusActions' => [], 'reactions' => [],
            'legendary' => [], 'mythic' => [], 'spellcasting' => null,
        ];
    }

    public static function abilityMod(int $score): int
    {
        return (int) floor(($score - 10) / 2);
    }

    /**
     * Coerce an untrusted block (e.g. AI-generated JSON) into the canonical shape: string fields kept
     * only when non-empty strings, ability scores clamped to 1–30, and each action group normalised to
     * a list of {name, desc}. Missing keys fall back to the empty template. Spellcasting is left null —
     * it's added later in the compendium editor.
     */
    public static function sanitise(mixed $input): array
    {
        $base = self::empty();
        if (! is_array($input)) {
            return $base;
        }

        $stringKeys = ['size', 'type', 'alignment', 'ac', 'hp', 'speed', 'cr', 'saves', 'skills',
            'vulnerabilities', 'resistances', 'immunities', 'conditionImmunities', 'senses', 'languages',
            'legendaryResistance'];
        foreach ($stringKeys as $key) {
            if (isset($input[$key]) && (is_string($input[$key]) || is_numeric($input[$key]))) {
                $value = self::text($input[$key]);
                if ($value !== '') {
                    $base[$key] = $value;
                }
            }
        }

        if (is_array($input['abilities'] ?? null)) {
            foreach (self::ABILITIES as $ability) {
                $score = $input['abilities'][$ability] ?? null;
                if (is_numeric($score)) {
                    $base['abilities'][$ability] = max(1, min(30, (int) $score));
                }
            }
        }

        foreach (['traits', 'actions', 'bonusActions', 'reactions', 'legendary', 'mythic'] as $group) {
            $base[$group] = self::entries($input[$group] ?? null);
        }

        return $base;
    }

    protected static function signed(int $n): string
    {
        return ($n >= 0 ? '+' : '').$n;
    }

    protected static function text($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return implode(', ', array_filter(array_map([self::class, 'text'], $value)));
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return trim((string) $value);
    }

    protected static function speedText($value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (! is_array($value)) {
            return '';
        }
        $hover = ($value['hover'] ?? false) === true;
        $parts = [];
        foreach ($value as $key => $v) {
            if ($key === 'hover' || ! is_numeric($v)) {
                continue;
            }
            $parts[] = "{$key} {$v} ft.";
        }
        if (empty($parts)) {
            return '';
        }

        return implode(', ', $parts).($hover ? ' (hover)' : '');
    }

    protected static function entries($value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $row) {
            $name = self::text($row['name'] ?? '');
            $desc = self::text($row['desc'] ?? '');
            if ($name !== '' || $desc !== '') {
                $out[] = ['name' => $name, 'desc' => $desc];
            }
        }

        return $out;
    }

    /**
     * Map a raw Open5e monster record into the structured block. Returns null when the record has no
     * ability scores (i.e. it is not a monster we can structure).
     */
    public static function fromOpen5e($data): ?array
    {
        if (! is_array($data)) {
            return null;
        }

        $scores = ['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'];
        $present = array_filter($scores, fn ($k) => is_numeric($data[$k] ?? null));
        if (empty($present)) {
            return null;
        }

        $base = self::empty();
        $ac = self::text($data['armor_class'] ?? '');
        $acDesc = self::text($data['armor_desc'] ?? '');
        $hp = self::text($data['hit_points'] ?? '');
        $hitDice = self::text($data['hit_dice'] ?? '');
        $type = self::text($data['type'] ?? '');
        $subtype = self::text($data['subtype'] ?? '');

        $saveKeys = ['strength_save' => 'Str', 'dexterity_save' => 'Dex', 'constitution_save' => 'Con',
            'intelligence_save' => 'Int', 'wisdom_save' => 'Wis', 'charisma_save' => 'Cha'];
        $saves = [];
        foreach ($saveKeys as $key => $label) {
            if (is_numeric($data[$key] ?? null)) {
                $saves[] = "{$label} ".self::signed((int) $data[$key]);
            }
        }

        $skills = [];
        if (is_array($data['skills'] ?? null)) {
            foreach ($data['skills'] as $name => $bonus) {
                if (is_numeric($bonus)) {
                    $skills[] = ucfirst($name).' '.self::signed((int) $bonus);
                }
            }
        }

        return array_merge($base, [
            'size' => self::text($data['size'] ?? '') ?: $base['size'],
            'type' => $subtype ? "{$type} ({$subtype})" : ($type ?: $base['type']),
            'alignment' => self::text($data['alignment'] ?? '') ?: $base['alignment'],
            'ac' => $ac ? ($acDesc ? "{$ac} ({$acDesc})" : $ac) : $base['ac'],
            'hp' => $hp ? ($hitDice ? "{$hp} ({$hitDice})" : $hp) : $base['hp'],
            'speed' => self::speedText($data['speed'] ?? null) ?: $base['speed'],
            'abilities' => [
                'str' => (int) ($data['strength'] ?? 10), 'dex' => (int) ($data['dexterity'] ?? 10),
                'con' => (int) ($data['constitution'] ?? 10), 'int' => (int) ($data['intelligence'] ?? 10),
                'wis' => (int) ($data['wisdom'] ?? 10), 'cha' => (int) ($data['charisma'] ?? 10),
            ],
            'saves' => implode(', ', $saves),
            'skills' => implode(', ', $skills),
            'vulnerabilities' => self::text($data['damage_vulnerabilities'] ?? ''),
            'resistances' => self::text($data['damage_resistances'] ?? ''),
            'immunities' => self::text($data['damage_immunities'] ?? ''),
            'conditionImmunities' => self::text($data['condition_immunities'] ?? ''),
            'senses' => self::text($data['senses'] ?? ''),
            'languages' => self::text($data['languages'] ?? ''),
            'cr' => self::text($data['challenge_rating'] ?? $data['cr'] ?? '') ?: $base['cr'],
            'traits' => self::entries($data['special_abilities'] ?? null),
            'actions' => self::entries($data['actions'] ?? null),
            'bonusActions' => self::entries($data['bonus_actions'] ?? null),
            'reactions' => self::entries($data['reactions'] ?? null),
            'legendary' => self::entries($data['legendary_actions'] ?? null),
            'mythic' => self::entries($data['mythic_actions'] ?? null),
        ]);
    }

    /**
     * Prepend a Spellcasting trait (intro + a line of italicised spells per level) to the traits list.
     *
     * @param  list<array{name?: string, desc?: string}>  $traits
     * @return list<array{name: string, desc: string}>
     */
    protected static function withSpellcasting(array $traits, mixed $spellcasting): array
    {
        if (! is_array($spellcasting)) {
            return $traits;
        }

        $intro = trim((string) ($spellcasting['intro'] ?? ''));
        $lines = [];
        foreach (is_array($spellcasting['groups'] ?? null) ? $spellcasting['groups'] : [] as $group) {
            $label = trim((string) ($group['label'] ?? ''));
            $names = [];
            foreach (is_array($group['spells'] ?? null) ? $group['spells'] : [] as $spell) {
                $spellName = trim((string) ($spell['name'] ?? ''));
                if ($spellName !== '') {
                    $names[] = "*{$spellName}*";
                }
            }
            if ($label === '' && $names === []) {
                continue;
            }
            $lines[] = ($label !== '' ? "**{$label}:** " : '').implode(', ', $names);
        }

        if ($intro === '' && $lines === []) {
            return $traits;
        }

        $desc = $intro;
        if ($lines !== []) {
            $desc .= ($intro !== '' ? "\n\n" : '').implode("\n", $lines);
        }

        return array_merge([['name' => 'Spellcasting', 'desc' => $desc]], $traits);
    }

    protected static function line(string $label, ?string $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? "**{$label}** {$value}\n" : '';
    }

    protected static function section(string $title, array $items): string
    {
        $rows = [];
        foreach ($items as $item) {
            $name = trim($item['name'] ?? '') ?: 'Unnamed';
            $desc = trim($item['desc'] ?? '');
            $rows[] = "***{$name}.*** {$desc}";
        }

        return empty($rows) ? '' : "\n### {$title}\n\n".implode("\n\n", $rows)."\n";
    }

    /** Render a block to homebrew markdown so the reader/preview stay in sync. */
    public static function toMarkdown(array $b, string $title): string
    {
        // Merge over defaults, then drop any nulls (Laravel converts empty form strings to null).
        $b = array_merge(self::empty(), array_filter($b, fn ($v) => $v !== null));
        $a = array_merge(self::empty()['abilities'], array_filter((array) ($b['abilities'] ?? []), fn ($v) => $v !== null));
        $cell = fn (string $k) => ((int) $a[$k]).' ('.self::signed(self::abilityMod((int) $a[$k])).')';

        $alignment = trim((string) $b['alignment']) !== '' ? ", {$b['alignment']}" : '';

        return "# {$title}\n\n"
            ."_{$b['size']} {$b['type']}{$alignment}_\n\n---\n\n"
            .self::line('Armor Class', $b['ac'])
            .self::line('Hit Points', $b['hp'])
            .self::line('Speed', $b['speed'])
            ."\n| STR | DEX | CON | INT | WIS | CHA |\n"
            ."| --- | --- | --- | --- | --- | --- |\n"
            .'| '.$cell('str').' | '.$cell('dex').' | '.$cell('con').' | '
            .$cell('int').' | '.$cell('wis').' | '.$cell('cha')." |\n\n---\n\n"
            .self::line('Saving Throws', $b['saves'])
            .self::line('Skills', $b['skills'])
            .self::line('Damage Vulnerabilities', $b['vulnerabilities'])
            .self::line('Damage Resistances', $b['resistances'])
            .self::line('Damage Immunities', $b['immunities'])
            .self::line('Condition Immunities', $b['conditionImmunities'])
            .self::line('Senses', $b['senses'])
            .self::line('Languages', $b['languages'])
            .self::line('Legendary Resistances', $b['legendaryResistance'] ?? '')
            .self::line('Challenge', $b['cr'])
            .self::section('Traits', self::withSpellcasting($b['traits'] ?? [], $b['spellcasting'] ?? null))
            .self::section('Actions', $b['actions'])
            .self::section('Bonus Actions', $b['bonusActions'])
            .self::section('Reactions', $b['reactions'])
            .self::section('Legendary Actions', $b['legendary'])
            .self::section('Mythic Actions', $b['mythic'] ?? []);
    }
}
