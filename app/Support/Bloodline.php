<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads a bloodline document's family tree: a flat list of members, each with an optional linked entry
 * (usually an NPC), the parent relationships it descends from (each with a type — biological, adopted,
 * step…), and its partners (spouses). References to members that don't exist are dropped, and links are
 * resolved only against the visible-entry map so a private target never leaks.
 */
class Bloodline
{
    /** @var list<string> */
    public const PARENT_TYPES = ['biological', 'adopted', 'step', 'foster'];

    /**
     * @param  array<int, array{type: string, slug: string, title: string, image: string|null}>  $linkMap  visible entries a member may link to, keyed by id
     * @return list<array{id: string, name: string, subtitle: string, link: array{type: string, slug: string, title: string, image: string|null}|null, image: string|null, parents: list<array{id: string, type: string}>, partners: list<string>}>
     */
    public static function members(mixed $document, array $linkMap = []): array
    {
        $raw = data_get($document, 'data.members');

        if (! is_array($raw)) {
            return [];
        }

        $members = [];
        $ids = [];
        foreach ($raw as $entry) {
            $id = trim((string) data_get($entry, 'id', ''));
            if ($id === '' || isset($ids[$id])) {
                continue;
            }
            $ids[$id] = true;
            $link = self::resolveLink(data_get($entry, 'link'), $linkMap);
            $members[] = [
                'id' => $id,
                'name' => trim((string) data_get($entry, 'name', '')),
                'subtitle' => trim((string) data_get($entry, 'subtitle', '')),
                'link' => $link,
                // A linked member wears the linked entry's portrait; otherwise its own uploaded image.
                'image' => $link['image'] ?? (trim((string) data_get($entry, 'image', '')) ?: null),
                'parents' => self::parents($entry),
                'partners' => collect((array) data_get($entry, 'partners', []))
                    ->map(fn (mixed $partner): string => trim((string) $partner))
                    ->filter()
                    ->values()
                    ->all(),
            ];
        }

        // Keep only references that point at another real member (never itself).
        return collect($members)
            ->map(fn (array $member): array => [
                ...$member,
                'parents' => collect($member['parents'])
                    ->filter(fn (array $parent): bool => isset($ids[$parent['id']]) && $parent['id'] !== $member['id'])
                    ->values()
                    ->all(),
                'partners' => collect($member['partners'])
                    ->filter(fn (string $partner): bool => isset($ids[$partner]) && $partner !== $member['id'])
                    ->unique()
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * Normalise a member's parents to {id, type}, accepting both the legacy shape (a bare id string) and
     * the richer shape ({id, type}). Unknown types fall back to biological.
     *
     * @return list<array{id: string, type: string}>
     */
    private static function parents(mixed $entry): array
    {
        return collect((array) data_get($entry, 'parents', []))
            ->map(function (mixed $parent): ?array {
                $id = trim((string) (is_array($parent) ? data_get($parent, 'id', '') : $parent));
                if ($id === '') {
                    return null;
                }
                $type = is_array($parent) ? (string) data_get($parent, 'type', 'biological') : 'biological';

                return ['id' => $id, 'type' => in_array($type, self::PARENT_TYPES, true) ? $type : 'biological'];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{type: string, slug: string, title: string}>  $linkMap
     * @return array{type: string, slug: string, title: string}|null
     */
    private static function resolveLink(mixed $id, array $linkMap): ?array
    {
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        return $linkMap[(int) $id] ?? null;
    }
}
