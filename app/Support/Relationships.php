<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical relationship types for connections between entries. Each carries a forward phrase (how
 * A → B reads) and an inverse phrase (how the same link reads from B), so both ends of a connection
 * read naturally in the graph and the neighbours list. Anything not in this set is treated as a
 * custom free-text label supplied on the link itself.
 */
class Relationships
{
    /** @var array<string, array{label: string, inverse: string}> */
    public const TYPES = [
        'related_to' => ['label' => 'Related to', 'inverse' => 'Related to'],
        'mentions' => ['label' => 'Mentions', 'inverse' => 'Mentioned by'],
        'located_in' => ['label' => 'Located in', 'inverse' => 'Contains'],
        'part_of' => ['label' => 'Part of', 'inverse' => 'Has part'],
        'member_of' => ['label' => 'Member of', 'inverse' => 'Has member'],
        'ally_of' => ['label' => 'Ally of', 'inverse' => 'Ally of'],
        'rival_of' => ['label' => 'Rival of', 'inverse' => 'Rival of'],
        'parent_of' => ['label' => 'Parent of', 'inverse' => 'Child of'],
        'owns' => ['label' => 'Owns', 'inverse' => 'Owned by'],
        'created_by' => ['label' => 'Created by', 'inverse' => 'Creator of'],
        'serves' => ['label' => 'Serves', 'inverse' => 'Served by'],
    ];

    public const DEFAULT = 'related_to';

    public static function isKnown(string $key): bool
    {
        return isset(self::TYPES[$key]);
    }

    /** Coerce any value to a known key, falling back to the default. */
    public static function normalise(?string $key): string
    {
        return $key !== null && isset(self::TYPES[$key]) ? $key : self::DEFAULT;
    }

    /** Forward phrase — a custom label (if any) wins over the type's phrase. */
    public static function label(?string $key, ?string $custom = null): string
    {
        if (filled($custom)) {
            return (string) $custom;
        }

        return self::TYPES[self::normalise($key)]['label'];
    }

    /** Inverse phrase — a custom label reads the same in both directions. */
    public static function inverse(?string $key, ?string $custom = null): string
    {
        if (filled($custom)) {
            return (string) $custom;
        }

        return self::TYPES[self::normalise($key)]['inverse'];
    }

    /**
     * Options for a relationship picker.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::TYPES)
            ->map(fn (array $type, string $key) => ['key' => $key, 'label' => $type['label']])
            ->values()
            ->all();
    }
}
