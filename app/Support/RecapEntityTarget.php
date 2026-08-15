<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Document;

/**
 * Maps a recap entity type to where its real counterpart lives in a world: lore {@see Document}
 * entries (people, places, factions, mundane items) or the Compendium (monsters and spells, which carry
 * mechanics). Schema-native — one target system and kind per type — used to match, search and create.
 */
final class RecapEntityTarget
{
    /** type => [system, kind], where system is 'document' or 'compendium'. */
    private const MAP = [
        'npc' => ['document', 'npc'],
        'location' => ['document', 'location'],
        'faction' => ['document', 'faction'],
        'item' => ['document', 'item'],
        'monster' => ['compendium', 'monster'],
        'spell' => ['compendium', 'spell'],
    ];

    public static function system(string $type): string
    {
        return self::MAP[$type][0] ?? 'document';
    }

    public static function kind(string $type): string
    {
        return self::MAP[$type][1] ?? $type;
    }

    public static function isCompendium(string $type): bool
    {
        return self::system($type) === 'compendium';
    }
}
