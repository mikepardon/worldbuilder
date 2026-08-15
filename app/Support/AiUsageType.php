<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalises a metered AI call's (feature, detail) into a human content-type label — Recap, Session,
 * Location, Monster, Lore, and so on — so usage can be broken down and priced by what was produced rather
 * than by which code path produced it. Most features record the kind in `detail` (a document kind or a
 * compendium item type); a few features are a content type in themselves.
 */
final class AiUsageType
{
    /** Features that denote a content type on their own, whatever their detail. */
    private const DIRECT = [
        'session_recap' => 'Recap',
        'session_writeup' => 'Session',
        'assistant_ask' => 'Assistant chat',
    ];

    /** Features whose `detail` holds the content kind (a document kind or a compendium item type). */
    private const KIND_FEATURES = ['assistant_draft', 'generator', 'compendium_draft', 'compendium_admin'];

    /** Friendly names for the kinds recorded in `detail`. */
    private const KIND_LABELS = [
        'npc' => 'NPC',
        'location' => 'Location',
        'faction' => 'Faction',
        'item' => 'Item',
        'lore' => 'Lore',
        'quest' => 'Quest',
        'timeline' => 'Timeline',
        'rule' => 'Rule',
        'article' => 'Article',
        'session' => 'Session',
        'statblock' => 'Statblock',
        'monster' => 'Monster',
        'spell' => 'Spell',
        'magicitem' => 'Magic item',
        'equipment' => 'Equipment',
        'condition' => 'Condition',
        'race' => 'Race',
        'feat' => 'Feat',
    ];

    /**
     * The raw kind keys recorded in `detail` for kind-based features (npc, monster, …), used to build the
     * front-end's per-kind credit-cost map.
     *
     * @return list<string>
     */
    public static function kindKeys(): array
    {
        return array_keys(self::KIND_LABELS);
    }

    public static function label(string $feature, ?string $detail): string
    {
        if (isset(self::DIRECT[$feature])) {
            return self::DIRECT[$feature];
        }

        if (in_array($feature, self::KIND_FEATURES, true) && filled($detail)) {
            return self::KIND_LABELS[$detail] ?? Str::headline($detail);
        }

        // Legacy or detail-less features: humanise the raw tag (e.g. "session_ingest" → "Session Ingest").
        return Str::headline($feature);
    }
}
