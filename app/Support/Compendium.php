<?php

namespace App\Support;

/** Compendium item types and their display labels. */
class Compendium
{
    public const TYPES = [
        'spell' => ['label' => 'Spell', 'plural' => 'Spells'],
        'monster' => ['label' => 'Monster', 'plural' => 'Monsters'],
        'magicitem' => ['label' => 'Magic item', 'plural' => 'Magic items'],
        'equipment' => ['label' => 'Equipment', 'plural' => 'Equipment'],
        'condition' => ['label' => 'Condition', 'plural' => 'Conditions'],
        'race' => ['label' => 'Race', 'plural' => 'Races'],
        'feat' => ['label' => 'Feat', 'plural' => 'Feats'],
    ];

    /** Friendly names for where an imported entry came from ({@see CampaignCompendiumItem::$origin}). */
    public const ORIGINS = [
        'ddb' => 'D&D Beyond',
        'critterdb' => 'CritterDB',
        'open5e' => 'Open5e',
        'dnd5eapi' => 'D&D 5e API',
    ];

    public static function keys(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * The label shown in the compendium's "Source" column. Prefers the true origin of an imported
     * entry; falls back to whether it's a locked library copy or hand-authored homebrew.
     */
    public static function sourceLabel(?string $origin, string $provider): string
    {
        return self::ORIGINS[$origin] ?? ($provider === 'imported' ? 'Library' : 'Homebrew');
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? ucfirst($type);
    }

    /** Types with the item counts for a set of items, for the filter tabs. */
    public static function withCounts(iterable $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            $counts[$item->item_type] = ($counts[$item->item_type] ?? 0) + 1;
        }
        $out = [];
        foreach (self::TYPES as $key => $meta) {
            $out[] = ['type' => $key, 'plural' => $meta['plural'], 'count' => $counts[$key] ?? 0];
        }

        return $out;
    }
}
