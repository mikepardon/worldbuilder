<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The mechanical half of a generated magic item — the D&D 5e crunch (type, rarity, attunement, and
 * rules text) that turns flavour into something usable at the table. Maps onto the compendium's
 * `magicitem` fields so a generated item imports as a real, editable compendium entry.
 */
class ItemBlock
{
    public const CATEGORIES = ['Wondrous item', 'Weapon', 'Armor', 'Ring', 'Rod', 'Staff', 'Wand', 'Potion', 'Scroll'];

    public const RARITIES = ['Common', 'Uncommon', 'Rare', 'Very rare', 'Legendary', 'Artifact'];

    /** @return array{category: string, rarity: string, attunement: string, mechanics: string} */
    public static function sanitise(mixed $input): array
    {
        $string = fn (string $key): string => is_array($input) && is_string($input[$key] ?? null)
            ? trim($input[$key])
            : '';

        return [
            'category' => $string('category') !== '' ? $string('category') : 'Wondrous item',
            'rarity' => $string('rarity') !== '' ? $string('rarity') : 'Uncommon',
            'attunement' => $string('attunement') !== '' ? $string('attunement') : 'No',
            'mechanics' => $string('mechanics'),
        ];
    }

    /**
     * Map a sanitised item block onto the compendium `magicitem` field schema, folding the mechanics and
     * any flavour into the single description field (mechanics first, so the crunch reads up top).
     *
     * @param  array{category: string, rarity: string, attunement: string, mechanics: string}  $item
     * @return array{category: string, rarity: string, attunement: string, description: string}
     */
    public static function toFields(array $item, string $flavour = ''): array
    {
        $description = trim($item['mechanics'] ?? '');
        $flavour = trim($flavour);
        if ($flavour !== '') {
            $description = $description !== '' ? $description."\n\n".$flavour : $flavour;
        }

        return [
            'category' => $item['category'] ?? '',
            'rarity' => $item['rarity'] ?? '',
            'attunement' => $item['attunement'] ?? '',
            'description' => $description,
        ];
    }
}
