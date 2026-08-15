<?php

namespace App\Support;

/**
 * Document kinds and the reader "sections" that group them. Shared by the GM workspace and the
 * public reader so both stay in sync (mirrors the original app's SECTIONS/DOC_KINDS).
 */
class Sections
{
    public const KINDS = [
        'article', 'location', 'npc', 'faction', 'bloodline', 'timeline', 'item',
        'rule', 'session', 'quest', 'lore', 'spell', 'statblock',
    ];

    public const SECTIONS = [
        ['slug' => 'articles', 'label' => 'Articles', 'kinds' => ['article']],
        ['slug' => 'locations', 'label' => 'Locations', 'kinds' => ['location']],
        ['slug' => 'people', 'label' => 'People', 'kinds' => ['npc', 'faction', 'bloodline']],
        ['slug' => 'timelines', 'label' => 'Timelines', 'kinds' => ['timeline']],
        ['slug' => 'data', 'label' => 'Data', 'kinds' => ['item', 'rule']],
        ['slug' => 'sessions', 'label' => 'Sessions', 'kinds' => ['session', 'quest']],
        ['slug' => 'lore', 'label' => 'Lore', 'kinds' => ['lore']],
    ];

    /** The URL path segment used for each kind (e.g. npc → "person"), for /w/{world}/{type}/{slug}. */
    public const TYPE_SLUGS = [
        'article' => 'article', 'location' => 'location', 'npc' => 'person', 'faction' => 'faction',
        'bloodline' => 'bloodline', 'timeline' => 'timeline', 'item' => 'item', 'rule' => 'rule',
        'session' => 'session', 'quest' => 'quest', 'lore' => 'lore', 'spell' => 'spell', 'statblock' => 'statblock',
    ];

    public static function typeSlug(string $kind): string
    {
        return self::TYPE_SLUGS[$kind] ?? $kind;
    }

    /** Resolve a URL type segment back to its document kind, or null if unknown. */
    public static function kindForTypeSlug(string $type): ?string
    {
        return array_search($type, self::TYPE_SLUGS, true) ?: null;
    }

    public static function kindLabel(string $kind): string
    {
        return [
            'article' => 'Article', 'location' => 'Location', 'npc' => 'Person', 'faction' => 'Faction',
            'bloodline' => 'Bloodline', 'timeline' => 'Timeline', 'item' => 'Data entry', 'rule' => 'Rule',
            'session' => 'Session', 'quest' => 'Quest', 'lore' => 'Lore',
            'spell' => 'Spell', 'statblock' => 'Stat block',
        ][$kind] ?? ucfirst($kind);
    }

    /** One-line description per kind, shown in the "new entry" category picker. */
    public const DESCRIPTIONS = [
        'article' => 'Free-form prose — essays, notes and write-ups.',
        'location' => 'Cities, dungeons, planes and places.',
        'npc' => 'Characters and the people of your world.',
        'faction' => 'Guilds, cults, kingdoms and organisations.',
        'bloodline' => 'A family tree of a dynasty or lineage.',
        'timeline' => 'An age of history and its dated events.',
        'item' => 'Artefacts, relics and notable objects.',
        'rule' => 'House rules and homebrew mechanics.',
        'session' => 'Session notes and play recaps.',
        'quest' => 'Quests, hooks and objectives.',
        'lore' => 'Myths, history and deep background.',
        'spell' => 'Spells written up as world entries.',
        'statblock' => 'Creature stat blocks as entries.',
    ];

    /**
     * The categories a GM can create, each with its label, blurb, and whether it opens with a
     * structured field template ({@see DocFields}). Drives the category picker.
     *
     * @return list<array{kind: string, label: string, description: string, hasTemplate: bool}>
     */
    public static function categories(): array
    {
        return array_map(fn (string $kind): array => [
            'kind' => $kind,
            'label' => self::kindLabel($kind),
            'description' => self::DESCRIPTIONS[$kind] ?? '',
            'hasTemplate' => DocFields::isFormKind($kind),
        ], self::KINDS);
    }

    /** The section slug a given kind belongs to. */
    public static function sectionForKind(string $kind): ?array
    {
        foreach (self::SECTIONS as $section) {
            if (in_array($kind, $section['kinds'], true)) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Section list with live counts for a set of documents. An optional slug→image-URL map decorates
     * each section with the GM-set "door" image ({@see World::sectionImages()}); sections without one
     * carry a null image so the reader can fall back to its placeholder.
     *
     * @param  array<string, string>  $images
     */
    public static function withCounts(iterable $documents, array $images = []): array
    {
        return array_values(array_filter(array_map(function ($section) use ($documents, $images) {
            $count = 0;
            foreach ($documents as $doc) {
                if (in_array($doc->kind, $section['kinds'], true)) {
                    $count++;
                }
            }

            return $count > 0
                ? [...$section, 'count' => $count, 'image' => $images[$section['slug']] ?? null]
                : null;
        }, self::SECTIONS)));
    }
}
