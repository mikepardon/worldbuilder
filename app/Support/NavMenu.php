<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The reader's navigation menu: a WordPress-style tree of items the GM arranges in the world's
 * Settings. Stored raw in {@see \App\Models\World} settings under `nav_menu`; resolved to real
 * hrefs/labels/counts client-side by the reader (PublicLayout.vue), which drops items whose target
 * is unavailable to the viewer (a disabled feature, an empty section, a deleted entry).
 *
 * A node is: {id, type, label, target, children[]}. `target` meaning depends on `type`:
 *   - page:     one of {@see self::PAGES} (overview, compendium, web, campaigns, maps)
 *   - section:  a section slug ({@see Sections::SECTIONS})
 *   - campaign: a campaign slug
 *   - entry:    "typeSlug:entrySlug" (e.g. "location:the-ninth-house")
 *   - link:     an external URL
 */
class NavMenu
{
    /** @var list<string> */
    public const TYPES = ['page', 'section', 'campaign', 'entry', 'link'];

    /** Built-in reader pages that can be placed in the menu, keyed by target slug. */
    public const PAGES = [
        'overview' => 'Overview',
        'compendium' => 'Compendium',
        'web' => 'Web',
        'campaigns' => 'Campaigns',
        'maps' => 'Maps',
    ];

    /** Guard-rails so a hostile or buggy payload can't store an unbounded tree. */
    private const MAX_DEPTH = 4;

    private const MAX_PER_LEVEL = 60;

    private const MAX_LABEL = 60;

    private const MAX_TARGET = 255;

    private const MAX_ID = 40;

    /**
     * Clean an incoming (or stored) menu tree, rejecting anything malformed by default. Unknown types,
     * over-deep nesting, and empty non-container nodes are dropped; strings are trimmed and capped.
     *
     * @return list<array{id: string, type: string, label: string, target: string, children: list<mixed>}>
     */
    public static function sanitise(mixed $raw, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH || ! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $node) {
            if (count($out) >= self::MAX_PER_LEVEL) {
                break;
            }
            if (! is_array($node)) {
                continue;
            }

            $type = is_string($node['type'] ?? null) ? $node['type'] : '';
            if (! in_array($type, self::TYPES, true)) {
                continue;
            }

            $label = is_string($node['label'] ?? null) ? mb_substr(trim($node['label']), 0, self::MAX_LABEL) : '';
            $target = is_string($node['target'] ?? null) ? mb_substr(trim($node['target']), 0, self::MAX_TARGET) : '';
            $children = self::sanitise($node['children'] ?? [], $depth + 1);

            // A node with neither a target nor surviving children is dead weight — drop it.
            if ($target === '' && $children === []) {
                continue;
            }

            $id = is_string($node['id'] ?? null) && $node['id'] !== ''
                ? mb_substr($node['id'], 0, self::MAX_ID)
                : "n{$depth}-".count($out);

            $out[] = [
                'id' => $id,
                'type' => $type,
                'label' => $label,
                'target' => $target,
                'children' => $children,
            ];
        }

        return $out;
    }

    /**
     * The default menu every world falls back to before the GM customises it: Overview, each content
     * section, then Compendium / Web / Campaigns — carrying over any legacy hide/reorder/links choices
     * so worlds set up under the old flat nav keep their arrangement.
     *
     * @param  array{hidden: list<string>, order: list<string>, links: list<array{label: string, url: string}>}  $legacyNav
     * @return list<array{id: string, type: string, label: string, target: string, children: list<mixed>}>
     */
    public static function defaultTree(array $legacyNav): array
    {
        $hidden = array_flip($legacyNav['hidden'] ?? []);
        $order = $legacyNav['order'] ?? [];
        $rank = static fn (string $slug): int => ($index = array_search($slug, $order, true)) === false
            ? PHP_INT_MAX
            : (int) $index;

        $sections = Sections::SECTIONS;
        usort($sections, static fn (array $a, array $b): int => $rank($a['slug']) <=> $rank($b['slug']));

        $tree = [self::node('page', 'Overview', 'overview')];
        foreach ($sections as $section) {
            if (! isset($hidden[$section['slug']])) {
                $tree[] = self::node('section', $section['label'], $section['slug']);
            }
        }
        $tree[] = self::node('page', 'Compendium', 'compendium');
        $tree[] = self::node('page', 'Web', 'web');
        $tree[] = self::node('page', 'Campaigns', 'campaigns');

        foreach ($legacyNav['links'] ?? [] as $link) {
            if (($link['label'] ?? '') !== '' && ($link['url'] ?? '') !== '') {
                $tree[] = self::node('link', (string) $link['label'], (string) $link['url']);
            }
        }

        return $tree;
    }

    /**
     * Every `target` of the given type anywhere in the tree — so the reader can resolve just the
     * campaigns/entries it actually references, rather than loading them all.
     *
     * @param  list<array{type?: string, target?: string, children?: mixed}>  $tree
     * @return list<string>
     */
    public static function collectTargets(array $tree, string $type): array
    {
        $targets = [];
        foreach ($tree as $node) {
            if (($node['type'] ?? null) === $type && ($node['target'] ?? '') !== '') {
                $targets[] = (string) $node['target'];
            }
            if (is_array($node['children'] ?? null)) {
                $targets = [...$targets, ...self::collectTargets($node['children'], $type)];
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * @return array{id: string, type: string, label: string, target: string, children: list<mixed>}
     */
    private static function node(string $type, string $label, string $target): array
    {
        return [
            'id' => $type.'-'.$target,
            'type' => $type,
            'label' => $label,
            'target' => $target,
            'children' => [],
        ];
    }
}
