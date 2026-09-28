<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Structured field schemas for non-monster compendium types, plus a schema-driven Markdown renderer.
 * Monsters use the richer Statblock editor instead; every other type edits these fields and has its
 * `document` regenerated from them, so the reader/embeds stay in step.
 */
class CompendiumFields
{
    /** @var array<string, list<array{key: string, label: string, type: string, placeholder?: string, options?: list<string>}>> */
    private const SCHEMAS = [
        'spell' => [
            ['key' => 'level', 'label' => 'Level', 'type' => 'select', 'options' => ['Cantrip', '1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th']],
            ['key' => 'school', 'label' => 'School', 'type' => 'select', 'options' => ['Abjuration', 'Conjuration', 'Divination', 'Enchantment', 'Evocation', 'Illusion', 'Necromancy', 'Transmutation']],
            // Ascendancy spells are learned as a line of ranks: each rank costs its own mana and can differ in
            // name, range, targets, components and duration. `min_level` gates the character level needed to
            // learn that rank. A caster may cast any rank they've learned, paying that rank's mana.
            ['key' => 'levels', 'label' => 'Ranks (mana-costed levels)', 'type' => 'levels'],
            ['key' => 'casting_time', 'label' => 'Casting Time', 'type' => 'text', 'placeholder' => '1 action'],
            ['key' => 'range', 'label' => 'Range', 'type' => 'text', 'placeholder' => '150 feet'],
            ['key' => 'components', 'label' => 'Components', 'type' => 'text', 'placeholder' => 'V, S, M (a tiny ball of bat guano)'],
            ['key' => 'duration', 'label' => 'Duration', 'type' => 'text', 'placeholder' => 'Instantaneous'],
            ['key' => 'description', 'label' => 'Description', 'type' => 'longtext', 'placeholder' => 'What the spell does…'],
            ['key' => 'higher_levels', 'label' => 'At Higher Levels', 'type' => 'longtext'],
        ],
        // Weapons and armor share one "equipment" type (matching dnd5eapi's mixed endpoint); the
        // weapon-only or armor-only fields simply stay blank for the other kind.
        'equipment' => [
            ['key' => 'category', 'label' => 'Category', 'type' => 'text', 'placeholder' => 'Martial Melee Weapon / Heavy Armor / Adventuring gear'],
            ['key' => 'cost', 'label' => 'Cost', 'type' => 'text', 'placeholder' => '15 gp'],
            ['key' => 'weight', 'label' => 'Weight', 'type' => 'text', 'placeholder' => '3 lb.'],
            ['key' => 'damage', 'label' => 'Damage (weapons)', 'type' => 'text', 'placeholder' => '1d8'],
            ['key' => 'damage_type', 'label' => 'Damage Type (weapons)', 'type' => 'text', 'placeholder' => 'slashing'],
            ['key' => 'properties', 'label' => 'Properties (weapons)', 'type' => 'text', 'placeholder' => 'Versatile (1d10), finesse'],
            ['key' => 'ac', 'label' => 'Armor Class (armor)', 'type' => 'text', 'placeholder' => '14 + Dex modifier (max 2)'],
            ['key' => 'strength', 'label' => 'Strength Requirement (armor)', 'type' => 'text', 'placeholder' => 'Str 13'],
            ['key' => 'stealth', 'label' => 'Stealth (armor)', 'type' => 'select', 'options' => ['—', 'Disadvantage']],
            ['key' => 'description', 'label' => 'Description', 'type' => 'longtext'],
        ],
        'magicitem' => [
            ['key' => 'category', 'label' => 'Item Type', 'type' => 'select', 'options' => ['Wondrous item', 'Weapon', 'Armor', 'Ring', 'Rod', 'Staff', 'Wand', 'Potion', 'Scroll']],
            ['key' => 'rarity', 'label' => 'Rarity', 'type' => 'select', 'options' => ['Common', 'Uncommon', 'Rare', 'Very rare', 'Legendary', 'Artifact']],
            ['key' => 'attunement', 'label' => 'Attunement', 'type' => 'select', 'options' => ['No', 'Yes']],
            ['key' => 'description', 'label' => 'Description', 'type' => 'longtext'],
        ],
        'feat' => [
            ['key' => 'prerequisite', 'label' => 'Prerequisite', 'type' => 'text', 'placeholder' => 'Strength 13 or higher'],
            // Sorts the feat between a character sheet's Actions and Traits tabs when it's granted by a talent.
            ['key' => 'activation', 'label' => 'Activation', 'type' => 'select', 'options' => ['Passive', 'Action', 'Bonus action', 'Reaction']],
            ['key' => 'description', 'label' => 'Description', 'type' => 'longtext'],
        ],
        // A class/racial/talent ability. Like a feat, its activation sorts it between the Actions and
        // Traits tabs when a talent grants it.
        'ability' => [
            ['key' => 'activation', 'label' => 'Activation', 'type' => 'select', 'options' => ['Passive', 'Action', 'Bonus action', 'Reaction']],
            ['key' => 'uses', 'label' => 'Uses', 'type' => 'text', 'placeholder' => '3 / long rest'],
            ['key' => 'description', 'label' => 'Description', 'type' => 'longtext'],
        ],
        'condition' => [
            ['key' => 'description', 'label' => 'Description', 'type' => 'longtext', 'placeholder' => 'What the condition does…'],
        ],
        'race' => [
            ['key' => 'size', 'label' => 'Size', 'type' => 'select', 'options' => ['Tiny', 'Small', 'Medium', 'Large']],
            ['key' => 'speed', 'label' => 'Speed', 'type' => 'text', 'placeholder' => '30 feet'],
            ['key' => 'ability_bonuses', 'label' => 'Ability Score Increase', 'type' => 'text', 'placeholder' => '+2 Dexterity, +1 Wisdom'],
            // Structured base modifiers a character gets for being this race — matched to the campaign's
            // rule-system stat/resource/skill keys when the character is built. Free of the document.
            ['key' => 'stat_modifiers', 'label' => 'Base modifiers (rule system)', 'type' => 'effects'],
            // Spells/feats this race grants, chosen from the world's compendium.
            ['key' => 'grants', 'label' => 'Granted spells & feats', 'type' => 'grants'],
            ['key' => 'description', 'label' => 'Traits & Description', 'type' => 'longtext'],
        ],
    ];

    public static function has(string $type): bool
    {
        return isset(self::SCHEMAS[$type]);
    }

    /** @return list<array{key: string, label: string, type: string, placeholder?: string, options?: list<string>}> */
    public static function for(string $type): array
    {
        return self::SCHEMAS[$type] ?? [];
    }

    /**
     * Render a type's structured fields to the entry's Markdown document (heading, labelled stat lines,
     * then the longer body fields). Kept in step with the JS renderer in resources/js/lib/compendiumFields.js.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function toMarkdown(string $type, array $fields, string $name): string
    {
        $lines = [];
        $body = [];
        foreach (self::for($type) as $field) {
            $raw = $fields[$field['key']] ?? '';
            // Spell ranks render as their own labelled body section.
            if (($field['type'] ?? '') === 'levels') {
                $rendered = self::renderLevels(is_array($raw) ? $raw : []);
                if ($rendered !== '') {
                    $body[] = $rendered;
                }

                continue;
            }
            // Structured fields (effects, grants) hold arrays and aren't part of the rendered document.
            if (! is_scalar($raw)) {
                continue;
            }
            $value = trim((string) $raw);
            if ($value === '') {
                continue;
            }
            if (($field['type'] ?? 'text') === 'longtext') {
                $body[] = $field['key'] === 'description' ? $value : "***{$field['label']}.*** {$value}";
            } else {
                $lines[] = "**{$field['label']}** {$value}";
            }
        }

        $out = "#### {$name}\n\n";
        if ($lines !== []) {
            $out .= implode("\n", $lines)."\n\n";
        }
        if ($body !== []) {
            $out .= implode("\n\n", $body)."\n";
        }

        return trim($out)."\n";
    }

    /**
     * Render a spell's mana-costed ranks into a labelled document section.
     *
     * @param  list<mixed>  $levels
     */
    private static function renderLevels(array $levels): string
    {
        $rows = [];
        foreach ($levels as $level) {
            if (! is_array($level)) {
                continue;
            }
            $rank = trim((string) ($level['level'] ?? ''));
            if ($rank === '') {
                continue;
            }
            $name = trim((string) ($level['name'] ?? ''));
            $mana = trim((string) ($level['mana'] ?? ''));
            $head = "**Level {$rank}".($mana !== '' ? " · {$mana} mana" : '').($name !== '' ? " · {$name}" : '')."**";

            $facets = [];
            foreach (['casting_time' => 'Casting', 'range' => 'Range', 'targets' => 'Targets', 'components' => 'Components', 'duration' => 'Duration', 'min_level' => 'Requires level'] as $key => $label) {
                $value = trim((string) ($level[$key] ?? ''));
                if ($value !== '' && $value !== '0') {
                    $facets[] = "{$label} {$value}";
                }
            }

            $line = $head;
            if ($facets !== []) {
                $line .= ' — '.implode(', ', $facets);
            }
            $description = trim((string) ($level['description'] ?? ''));
            if ($description !== '') {
                $line .= "  \n{$description}";
            }
            $rows[] = $line;
        }

        return $rows === [] ? '' : "***Ranks.***\n\n".implode("\n\n", $rows);
    }

    /**
     * A short one-line summary for list views: a spell's rank/mana range (or its D&D level), else ''.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function meta(string $type, array $fields): string
    {
        if ($type !== 'spell') {
            return '';
        }

        $levels = $fields['levels'] ?? null;
        if (is_array($levels) && $levels !== []) {
            $ranks = [];
            $manas = [];
            foreach ($levels as $level) {
                if (! is_array($level)) {
                    continue;
                }
                if (isset($level['level']) && is_numeric($level['level'])) {
                    $ranks[] = (int) $level['level'];
                }
                if (isset($level['mana']) && is_numeric($level['mana'])) {
                    $manas[] = (int) $level['mana'];
                }
            }
            if ($ranks !== []) {
                $rankLabel = min($ranks) === max($ranks) ? 'L'.min($ranks) : 'L'.min($ranks).'–L'.max($ranks);
                $manaLabel = $manas === [] ? '' : (min($manas) === max($manas) ? ' · '.min($manas).' mana' : ' · '.min($manas).'–'.max($manas).' mana');

                return $rankLabel.$manaLabel;
            }
        }

        return trim((string) ($fields['level'] ?? ''));
    }
}
