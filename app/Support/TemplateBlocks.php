<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The block system behind the visual template builder. A template's layout is an ordered list of blocks
 * ({id, type, settings}); this class is the single source of truth for which block types exist, their
 * settings schema (which drives the builder's right-hand panel), their defaults, and how to normalise a
 * stored layout — including migrating a legacy {facts, width, banner, fields} layout into blocks.
 */
class TemplateBlocks
{
    /**
     * The "common" blocks — self-contained layout, content and media pieces that work on any page. They
     * live in the entry catalogue (their single source of truth) and are pulled into the home and archive
     * catalogues so every page gets the same building blocks.
     *
     * @var list<string>
     */
    private const SHARED = [
        'text', 'callout', 'quote', 'button', 'image', 'gallery', 'video',
        'accordion', 'tabs', 'stats', 'meter', 'faq', 'random', 'reusable', 'columns', 'spacer', 'divider',
    ];

    /**
     * The palette category each block sits in, so the builder can group its "Add block" list.
     *
     * @var array<string, string>
     */
    private const TYPE_GROUPS = [
        // Structure — the fixed skeleton of a page.
        'banner' => 'Structure', 'header' => 'Structure', 'content' => 'Structure',
        'hero' => 'Structure', 'heading' => 'Structure',
        // Content — prose and calls to action.
        'text' => 'Content', 'callout' => 'Content', 'readaloud' => 'Content', 'secret' => 'Content',
        'quote' => 'Content', 'button' => 'Content', 'accordion' => 'Content', 'tabs' => 'Content',
        'toc' => 'Content', 'search' => 'Content', 'filter' => 'Content', 'faq' => 'Content',
        'random' => 'Content', 'facets' => 'Content',
        // Media — images, galleries, video and maps.
        'image' => 'Media', 'gallery' => 'Media', 'video' => 'Media', 'map' => 'Media', 'avatar' => 'Media',
        // Sidebar widgets.
        'notes' => 'Data',
        // Data — anything drawn from the world's entries, facts or campaigns.
        'facts' => 'Data', 'related' => 'Data', 'stats' => 'Data', 'reference' => 'Data',
        'linked' => 'Data', 'events' => 'Data', 'featured' => 'Data', 'sections' => 'Data',
        'recent' => 'Data', 'spotlight' => 'Data', 'recaps' => 'Data', 'grid' => 'Data',
        'table' => 'Data', 'index' => 'Data', 'connections' => 'Data', 'comparison' => 'Data',
        'meter' => 'Data', 'nextsession' => 'Data', 'repeater' => 'Data',
        // Layout — splitting, spacing and shared blocks.
        'columns' => 'Layout', 'spacer' => 'Layout', 'divider' => 'Layout', 'reusable' => 'Layout',
    ];

    /** The order the builder shows the palette categories in. */
    public const GROUP_ORDER = ['Structure', 'Content', 'Media', 'Data', 'Layout'];

    /**
     * The block catalogue for a template target ('entry' styles a document, 'home' the reader home page,
     * 'archive' a section listing). Each type has a label, a palette hint, its category group, a settings
     * schema (drives the builder control), and the CSS classes it exposes for the per-block Custom CSS box.
     *
     * @return array<string, array{label: string, hint: string, group: string, settings: array<string, array<string, mixed>>, classes: list<array{selector: string, label: string}>}>
     */
    public static function types(string $target = 'entry'): array
    {
        $types = match ($target) {
            'home' => self::homeTypes(),
            'archive' => self::archiveTypes(),
            'block' => self::blockTargetTypes(),
            'sidebar' => self::sidebarTypes(),
            default => self::entryTypes(),
        };

        foreach ($types as $key => &$def) {
            $def['group'] = self::TYPE_GROUPS[$key] ?? 'Content';
        }

        return $types;
    }

    /**
     * The shared "common" block definitions, pulled from the entry catalogue in its declared order.
     *
     * @return array<string, array{label: string, hint: string, settings: array<string, array<string, mixed>>, classes: list<array{selector: string, label: string}>}>
     */
    private static function sharedTypes(): array
    {
        return array_intersect_key(self::entryTypes(), array_flip(self::SHARED));
    }

    /**
     * The blocks a reusable block set may contain: the shared "common" blocks, minus columns (reusable
     * content stays a flat list so it renders anywhere) and, of course, no nested reusables.
     *
     * @return array<string, array{label: string, hint: string, settings: array<string, array<string, mixed>>, classes: list<array{selector: string, label: string}>}>
     */
    private static function blockTargetTypes(): array
    {
        return collect(self::sharedTypes())->except(['columns', 'reusable'])->all();
    }

    /**
     * The blocks an entry's right-hand sidebar may hold: the built-in widgets (portrait, quick facts,
     * reader notes) plus the flat common blocks. Composed so a GM can rebuild the sidebar however they like.
     *
     * @return array<string, array{label: string, hint: string, settings: array<string, array<string, mixed>>, classes: list<array{selector: string, label: string}>}>
     */
    private static function sidebarTypes(): array
    {
        return [
            'avatar' => [
                'label' => 'Portrait',
                'hint' => 'The entry’s image, shown as a portrait.',
                'settings' => [
                    'shape' => ['type' => 'select', 'label' => 'Shape', 'default' => 'circle', 'options' => [
                        'circle' => 'Circle', 'rounded' => 'Rounded', 'square' => 'Square',
                    ]],
                ],
                'classes' => [['selector' => 'img', 'label' => 'The image']],
            ],
            'facts' => self::entryTypes()['facts'],
            'notes' => [
                'label' => 'Reader notes',
                'hint' => 'The signed-in reader’s private notes (and any they’ve shared with the GM).',
                'settings' => [],
                'classes' => [['selector' => ':root', 'label' => 'The notes panel']],
            ],
            ...self::blockTargetTypes(),
        ];
    }

    /** The default entry sidebar: portrait, quick facts, then the reader-notes panel. */
    public static function sidebarStarter(): array
    {
        return [
            self::block('avatar', [], 'sidebar'),
            self::block('facts', [], 'sidebar'),
            self::block('notes', [], 'sidebar'),
        ];
    }

    /**
     * The blocks that design a section's archive (listing) page.
     *
     * @return array<string, array{label: string, hint: string, settings: array<string, array<string, mixed>>, classes: list<array{selector: string, label: string}>}>
     */
    public static function archiveTypes(): array
    {
        return [
            'heading' => [
                'label' => 'Section heading',
                'hint' => 'The section’s title and entry count.',
                'settings' => [
                    'count' => ['type' => 'bool', 'label' => 'Show entry count', 'default' => true],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The heading'],
                    ['selector' => '.wb-title', 'label' => 'Section title'],
                ],
            ],
            'filter' => [
                'label' => 'Filter & sort bar',
                'hint' => 'A live search box and sort control for the entries below.',
                'settings' => [
                    'placeholder' => ['type' => 'text', 'label' => 'Placeholder', 'default' => 'Search this section…'],
                    'sort' => ['type' => 'bool', 'label' => 'Show sort control', 'default' => true],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The bar'],
                    ['selector' => '.wb-filter-input', 'label' => 'The search box'],
                ],
            ],
            'facets' => [
                'label' => 'Filter chips',
                'hint' => 'Kind and tag chips that narrow the entries below — readers tap to filter.',
                'settings' => [
                    'showKinds' => ['type' => 'bool', 'label' => 'Show kind chips', 'default' => true],
                    'showTags' => ['type' => 'bool', 'label' => 'Show tag chips', 'default' => true],
                    'maxTags' => ['type' => 'select', 'label' => 'Max tag chips', 'default' => 20, 'options' => [10 => '10', 20 => '20', 40 => '40', 100 => '100']],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The chip bar'],
                    ['selector' => '.wb-facet', 'label' => 'Each chip'],
                    ['selector' => '.wb-facet--on', 'label' => 'A selected chip'],
                ],
            ],
            'grid' => [
                'label' => 'Entry grid',
                'hint' => 'The grid of entry cards in this section.',
                'settings' => [
                    'columns' => ['type' => 'select', 'label' => 'Per row', 'default' => 3, 'options' => [1 => '1', 2 => '2', 3 => '3', 4 => '4']],
                    'sort' => ['type' => 'select', 'label' => 'Sort', 'default' => 'recent', 'options' => ['recent' => 'Recently updated', 'title' => 'A–Z']],
                    'showImage' => ['type' => 'bool', 'label' => 'Show image', 'default' => true],
                    'showSummary' => ['type' => 'bool', 'label' => 'Show summary', 'default' => true],
                ],
                'classes' => [['selector' => '.wb-card', 'label' => 'Each entry card']],
            ],
            'table' => [
                'label' => 'Table view',
                'hint' => 'A compact table of entries — choose which columns to show, including the section’s own fields.',
                'settings' => [
                    'showKind' => ['type' => 'bool', 'label' => 'Kind column', 'default' => true],
                    'summary' => ['type' => 'bool', 'label' => 'Summary column', 'default' => true],
                    'showUpdated' => ['type' => 'bool', 'label' => 'Updated column', 'default' => true],
                    'showCreated' => ['type' => 'bool', 'label' => 'Created column', 'default' => false],
                    // Extra columns drawn from the section's structured fields (Type, Owned by, Region…).
                    'fields' => ['type' => 'fields', 'label' => 'Field columns', 'default' => []],
                    'sort' => ['type' => 'select', 'label' => 'Sort', 'default' => 'recent', 'options' => ['recent' => 'Recently updated', 'title' => 'A–Z']],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The table'],
                    ['selector' => 'th', 'label' => 'Header cells'],
                    ['selector' => 'td', 'label' => 'Body cells'],
                ],
            ],
            'index' => [
                'label' => 'A–Z index',
                'hint' => 'An alphabetical index of entries with jump links.',
                'settings' => [
                    'jumpbar' => ['type' => 'bool', 'label' => 'Show A–Z jump bar', 'default' => true],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The index'],
                    ['selector' => '.wb-index-letter', 'label' => 'Each letter heading'],
                ],
            ],
            ...self::sharedTypes(),
        ];
    }

    /**
     * The blocks that design the reader's home page.
     *
     * @return array<string, array{label: string, hint: string, settings: array<string, array<string, mixed>>, classes: list<array{selector: string, label: string}>}>
     */
    public static function homeTypes(): array
    {
        return [
            'hero' => [
                'label' => 'Hero',
                'hint' => 'The banner, world name and description at the top.',
                'settings' => [
                    'stats' => ['type' => 'bool', 'label' => 'Show stats', 'default' => true],
                    'height' => ['type' => 'select', 'label' => 'Height', 'default' => 'md', 'options' => ['sm' => 'Short', 'md' => 'Medium', 'lg' => 'Tall']],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The hero'],
                    ['selector' => '.wb-title', 'label' => 'World name'],
                    ['selector' => '.wb-summary', 'label' => 'Description'],
                ],
            ],
            'featured' => [
                'label' => 'Featured',
                'hint' => 'Your pinned/featured entries as cards.',
                'settings' => [
                    'columns' => ['type' => 'select', 'label' => 'Per row', 'default' => 3, 'options' => [1 => '1', 2 => '2', 3 => '3', 4 => '4']],
                    'showImage' => ['type' => 'bool', 'label' => 'Show image', 'default' => true],
                    'showKind' => ['type' => 'bool', 'label' => 'Show kind label', 'default' => true],
                    'showSummary' => ['type' => 'bool', 'label' => 'Show summary', 'default' => false],
                ],
                'classes' => [
                    ['selector' => '.wb-card', 'label' => 'Each card'],
                ],
            ],
            'sections' => [
                'label' => 'Section doors',
                'hint' => '“Start here” cards linking to each section.',
                'settings' => [
                    'columns' => ['type' => 'select', 'label' => 'Per row', 'default' => 3, 'options' => [1 => '1', 2 => '2', 3 => '3', 4 => '4']],
                ],
                'classes' => [
                    ['selector' => '.wb-card', 'label' => 'Each section card'],
                ],
            ],
            'recent' => [
                'label' => 'Entries',
                'hint' => 'A grid of entries — pick which kind, how they’re sorted, and what each card shows.',
                'settings' => [
                    'title' => ['type' => 'text', 'label' => 'Heading', 'default' => 'Latest'],
                    'kinds' => ['type' => 'kinds', 'label' => 'Show', 'default' => [], 'groups' => self::kindGroups()],
                    'sort' => ['type' => 'select', 'label' => 'Sort by', 'default' => 'updated', 'options' => [
                        'updated' => 'Recently changed', 'created' => 'Newest', 'title' => 'A–Z',
                    ]],
                    'count' => ['type' => 'select', 'label' => 'How many', 'default' => 6, 'options' => [3 => '3', 6 => '6', 9 => '9', 12 => '12', 24 => '24']],
                    'columns' => ['type' => 'select', 'label' => 'Per row', 'default' => 1, 'options' => [1 => '1', 2 => '2', 3 => '3', 4 => '4']],
                    'showImage' => ['type' => 'bool', 'label' => 'Show image', 'default' => true],
                    'showKind' => ['type' => 'bool', 'label' => 'Show kind label', 'default' => true],
                    'showSummary' => ['type' => 'bool', 'label' => 'Show summary', 'default' => false],
                    'showDate' => ['type' => 'bool', 'label' => 'Show date', 'default' => false],
                ],
                'classes' => [
                    ['selector' => '.wb-card', 'label' => 'Each entry'],
                ],
            ],
            'search' => [
                'label' => 'Search bar',
                'hint' => 'A live search box across this world’s entries.',
                'settings' => [
                    'placeholder' => ['type' => 'text', 'label' => 'Placeholder', 'default' => 'Search the world…'],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The search block'],
                    ['selector' => '.wb-search-input', 'label' => 'The input'],
                ],
            ],
            'spotlight' => [
                'label' => 'Campaign spotlight',
                'hint' => 'Your playable campaigns as cards.',
                'settings' => [
                    'columns' => ['type' => 'select', 'label' => 'Columns', 'default' => 2, 'options' => [2 => '2', 3 => '3']],
                ],
                'classes' => [['selector' => '.wb-card', 'label' => 'Each campaign card']],
            ],
            'recaps' => [
                'label' => 'Session recaps',
                'hint' => 'The latest session recaps across your campaigns.',
                'settings' => [
                    'count' => ['type' => 'select', 'label' => 'How many', 'default' => 3, 'options' => [3 => '3', 5 => '5', 8 => '8']],
                ],
                'classes' => [['selector' => '.wb-card', 'label' => 'Each recap']],
            ],
            'nextsession' => [
                'label' => 'Next session',
                'hint' => 'A countdown to your next scheduled game.',
                'settings' => [
                    'title' => ['type' => 'text', 'label' => 'Heading', 'default' => 'Next session'],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The panel'],
                    ['selector' => '.wb-countdown', 'label' => 'The countdown'],
                ],
            ],
            ...self::sharedTypes(),
        ];
    }

    /**
     * The blocks that style a single entry (document).
     *
     * @return array<string, array{label: string, hint: string, settings: array<string, array<string, mixed>>, classes: list<array{selector: string, label: string}>}>
     */
    public static function entryTypes(): array
    {
        return [
            'banner' => [
                'label' => 'Banner',
                'hint' => 'The hero image at the top of the page.',
                'settings' => [
                    'height' => ['type' => 'select', 'label' => 'Height', 'default' => 'md', 'options' => ['sm' => 'Short', 'md' => 'Medium', 'lg' => 'Tall']],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The banner element itself'],
                ],
            ],
            'header' => [
                'label' => 'Title & summary',
                'hint' => 'The entry’s kind label, title, summary and reading time.',
                'settings' => [
                    'eyebrow' => ['type' => 'bool', 'label' => 'Show kind label', 'default' => true],
                    'summary' => ['type' => 'bool', 'label' => 'Show summary', 'default' => true],
                    'readingTime' => ['type' => 'bool', 'label' => 'Show reading time', 'default' => true],
                ],
                'classes' => [
                    ['selector' => '.wb-eyebrow', 'label' => 'Kind label'],
                    ['selector' => '.wb-title', 'label' => 'Title'],
                    ['selector' => '.wb-summary', 'label' => 'Summary'],
                    ['selector' => '.wb-readingtime', 'label' => 'Reading time'],
                ],
            ],
            'facts' => [
                'label' => 'Quick facts',
                'hint' => 'The entry’s structured fields (Population, Ruler…).',
                'settings' => [
                    'style' => ['type' => 'select', 'label' => 'Style', 'default' => 'card', 'options' => ['card' => 'Card', 'band' => 'Band']],
                    'columns' => ['type' => 'select', 'label' => 'Columns', 'default' => 2, 'options' => [1 => '1', 2 => '2', 3 => '3']],
                    'fields' => ['type' => 'fields', 'label' => 'Fields', 'default' => []],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The facts container'],
                    ['selector' => '.wb-facts-title', 'label' => '“Quick facts” heading'],
                    ['selector' => '.wb-fact', 'label' => 'Each fact row'],
                    ['selector' => '.wb-fact-label', 'label' => 'Field label'],
                    ['selector' => '.wb-fact-value', 'label' => 'Field value'],
                ],
            ],
            'content' => [
                'label' => 'Content',
                'hint' => 'The entry’s written body.',
                'settings' => [
                    'width' => ['type' => 'select', 'label' => 'Width', 'default' => 'normal', 'options' => ['normal' => 'Normal', 'wide' => 'Wide']],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The content wrapper'],
                    ['selector' => 'p', 'label' => 'Paragraphs'],
                    ['selector' => 'h2, h3', 'label' => 'Headings'],
                    ['selector' => 'a', 'label' => 'Links'],
                ],
            ],
            'related' => [
                'label' => 'Related entries',
                'hint' => 'The GM-curated related links.',
                'settings' => [
                    'columns' => ['type' => 'select', 'label' => 'Per row', 'default' => 2, 'options' => [1 => '1', 2 => '2', 3 => '3']],
                ],
                'classes' => [
                    ['selector' => '.wb-related-title', 'label' => '“Related” heading'],
                    ['selector' => '.wb-card', 'label' => 'Each related card'],
                ],
            ],
            'text' => [
                'label' => 'Custom text',
                'hint' => 'Your own Markdown, shown on every entry using this template.',
                'settings' => [
                    'markdown' => ['type' => 'textarea', 'label' => 'Markdown', 'default' => ''],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The text block'],
                    ['selector' => 'p', 'label' => 'Paragraphs'],
                    ['selector' => 'h2, h3', 'label' => 'Headings'],
                ],
            ],
            'callout' => [
                'label' => 'Callout',
                'hint' => 'A titled notice box (info, tip, warning, lore).',
                'settings' => [
                    'style' => ['type' => 'select', 'label' => 'Style', 'default' => 'info', 'options' => ['info' => 'Info', 'tip' => 'Tip', 'warning' => 'Warning', 'lore' => 'Lore']],
                    'title' => ['type' => 'text', 'label' => 'Title', 'default' => ''],
                    'markdown' => ['type' => 'textarea', 'label' => 'Body', 'default' => ''],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The box'],
                    ['selector' => '.wb-callout-title', 'label' => 'The title'],
                ],
            ],
            'readaloud' => [
                'label' => 'Read-aloud',
                'hint' => 'Boxed italic text for the GM to read to players.',
                'settings' => [
                    'markdown' => ['type' => 'textarea', 'label' => 'Text', 'default' => ''],
                ],
                'classes' => [['selector' => ':root', 'label' => 'The box']],
            ],
            'secret' => [
                'label' => 'GM secret',
                'hint' => 'Content only the GM sees on the page.',
                'settings' => [
                    'markdown' => ['type' => 'textarea', 'label' => 'Secret', 'default' => ''],
                ],
                'classes' => [['selector' => ':root', 'label' => 'The box']],
            ],
            'quote' => [
                'label' => 'Quote',
                'hint' => 'A styled pull-quote or epigraph.',
                'settings' => [
                    'text' => ['type' => 'textarea', 'label' => 'Quote', 'default' => ''],
                    'attribution' => ['type' => 'text', 'label' => 'Attribution', 'default' => ''],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The quote'],
                    ['selector' => '.wb-quote-cite', 'label' => 'Attribution'],
                ],
            ],
            'button' => [
                'label' => 'Button',
                'hint' => 'A call-to-action link.',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => 'Label', 'default' => 'Read more'],
                    'url' => ['type' => 'text', 'label' => 'Link (URL)', 'default' => ''],
                    'style' => ['type' => 'select', 'label' => 'Style', 'default' => 'solid', 'options' => ['solid' => 'Solid', 'outline' => 'Outline']],
                ],
                'classes' => [['selector' => ':root', 'label' => 'The button']],
            ],
            'spacer' => [
                'label' => 'Spacer',
                'hint' => 'Vertical space between blocks.',
                'settings' => [
                    'size' => ['type' => 'select', 'label' => 'Size', 'default' => 'md', 'options' => ['sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large']],
                ],
                'classes' => [],
            ],
            'toc' => [
                'label' => 'Table of contents',
                'hint' => 'Auto-generated from this entry’s headings.',
                'settings' => [],
                'classes' => [['selector' => ':root', 'label' => 'The list']],
            ],
            'stats' => [
                'label' => 'Stat highlights',
                'hint' => 'Big number tiles (Population, Founded, CR…).',
                'settings' => [
                    'columns' => ['type' => 'select', 'label' => 'Columns', 'default' => 3, 'options' => [2 => '2', 3 => '3', 4 => '4']],
                    'items' => ['type' => 'stats', 'label' => 'Tiles', 'default' => [['label' => '', 'value' => '']]],
                ],
                'classes' => [
                    ['selector' => '.wb-stat', 'label' => 'Each tile'],
                    ['selector' => '.wb-stat-value', 'label' => 'The number'],
                    ['selector' => '.wb-stat-label', 'label' => 'The label'],
                ],
            ],
            'image' => [
                'label' => 'Image',
                'hint' => 'An image from your media library.',
                'settings' => [
                    'url' => ['type' => 'image', 'label' => 'Image', 'default' => ''],
                    'caption' => ['type' => 'text', 'label' => 'Caption', 'default' => ''],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The figure'],
                    ['selector' => 'img', 'label' => 'The image'],
                ],
            ],
            'accordion' => [
                'label' => 'Accordion',
                'hint' => 'Collapsible panels (History, Rumors, Secrets…).',
                'settings' => [
                    'panes' => ['type' => 'panes', 'label' => 'Panels', 'default' => [['title' => '', 'markdown' => '']]],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The accordion'],
                    ['selector' => 'summary', 'label' => 'Each panel title'],
                ],
            ],
            'video' => [
                'label' => 'Video',
                'hint' => 'Embed a YouTube or Vimeo video.',
                'settings' => [
                    'url' => ['type' => 'text', 'label' => 'Video URL', 'default' => ''],
                ],
                'classes' => [['selector' => ':root', 'label' => 'The video frame']],
            ],
            'tabs' => [
                'label' => 'Tabs',
                'hint' => 'Tabbed panels of Markdown content.',
                'settings' => [
                    'panes' => ['type' => 'panes', 'label' => 'Tabs', 'default' => [['title' => '', 'markdown' => '']]],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The tabs'],
                    ['selector' => '.wb-tab', 'label' => 'Each tab button'],
                    ['selector' => '.wb-tab--active', 'label' => 'The active tab'],
                ],
            ],
            'gallery' => [
                'label' => 'Gallery',
                'hint' => 'A grid of images from your media library.',
                'settings' => [
                    'columns' => ['type' => 'select', 'label' => 'Columns', 'default' => 3, 'options' => [2 => '2', 3 => '3', 4 => '4']],
                    'images' => ['type' => 'images', 'label' => 'Images', 'default' => []],
                ],
                'classes' => [['selector' => 'img', 'label' => 'Each image']],
            ],
            'events' => [
                'label' => 'Mini-timeline',
                'hint' => 'A vertical list of dated events.',
                'settings' => [
                    'events' => ['type' => 'events', 'label' => 'Events', 'default' => [['when' => '', 'title' => '', 'detail' => '']]],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The timeline'],
                    ['selector' => '.wb-event-when', 'label' => 'The date'],
                ],
            ],
            'linked' => [
                'label' => 'Linked entries',
                'hint' => 'Hand-picked entries shown as cards.',
                'settings' => [
                    'columns' => ['type' => 'select', 'label' => 'Per row', 'default' => 2, 'options' => [1 => '1', 2 => '2', 3 => '3', 4 => '4']],
                    'ids' => ['type' => 'entries', 'label' => 'Entries', 'default' => []],
                ],
                'classes' => [['selector' => '.wb-card', 'label' => 'Each card']],
            ],
            'map' => [
                'label' => 'Map',
                'hint' => 'Embed one of your interactive maps, with its pins.',
                'settings' => [
                    'mapId' => ['type' => 'map', 'label' => 'Map', 'default' => null],
                    'height' => ['type' => 'select', 'label' => 'Height', 'default' => 'md', 'options' => ['sm' => 'Short', 'md' => 'Medium', 'lg' => 'Tall']],
                ],
                'classes' => [['selector' => ':root', 'label' => 'The map frame']],
            ],
            'connections' => [
                'label' => 'Connections',
                'hint' => 'This entry’s relationships — who and what it’s linked to.',
                'settings' => [
                    'columns' => ['type' => 'select', 'label' => 'Per row', 'default' => 2, 'options' => [1 => '1', 2 => '2', 3 => '3']],
                    'showRelationship' => ['type' => 'bool', 'label' => 'Show relationship label', 'default' => true],
                    'showImage' => ['type' => 'bool', 'label' => 'Show image', 'default' => true],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The list'],
                    ['selector' => '.wb-card', 'label' => 'Each connection'],
                    ['selector' => '.wb-connection-rel', 'label' => 'The relationship label'],
                ],
            ],
            'comparison' => [
                'label' => 'Comparison table',
                'hint' => 'Compare a few entries side by side by their quick facts.',
                'settings' => [
                    'ids' => ['type' => 'entries', 'label' => 'Entries', 'default' => []],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The table'],
                    ['selector' => 'th', 'label' => 'Header cells'],
                    ['selector' => 'td', 'label' => 'Body cells'],
                ],
            ],
            'repeater' => [
                'label' => 'Repeater',
                'hint' => 'Repeat a set of blocks once per related entry or connection. Inside, use {{ item.title }}, {{ item.url }}, {{ item.summary }}, {{ item.kind }}, {{ item.label }}.',
                'settings' => [
                    'source' => ['type' => 'select', 'label' => 'For each', 'default' => 'related', 'options' => [
                        'related' => 'Related entry', 'connections' => 'Connection',
                    ]],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The list'],
                    ['selector' => '.wb-repeater-item', 'label' => 'Each item'],
                ],
            ],
            'meter' => [
                'label' => 'Meter',
                'hint' => 'A labelled progress bar — great for a numeric field like threat or reputation. The value and max accept {{ variables }}.',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => 'Label', 'default' => ''],
                    'value' => ['type' => 'text', 'label' => 'Value', 'default' => ''],
                    'max' => ['type' => 'text', 'label' => 'Out of', 'default' => '100'],
                    'suffix' => ['type' => 'text', 'label' => 'Suffix (e.g. %)', 'default' => ''],
                    'colour' => ['type' => 'select', 'label' => 'Colour', 'default' => 'teal', 'options' => [
                        'teal' => 'Teal', 'amber' => 'Amber', 'red' => 'Red', 'green' => 'Green', 'violet' => 'Violet',
                    ]],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The meter'],
                    ['selector' => '.wb-meter-track', 'label' => 'The track'],
                    ['selector' => '.wb-meter-bar', 'label' => 'The filled bar'],
                ],
            ],
            'faq' => [
                'label' => 'FAQ',
                'hint' => 'Question-and-answer pairs (collapsible).',
                'settings' => [
                    'items' => ['type' => 'faq', 'label' => 'Questions', 'default' => [['question' => '', 'answer' => '']]],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The list'],
                    ['selector' => 'summary', 'label' => 'Each question'],
                ],
            ],
            'random' => [
                'label' => 'Random entry',
                'hint' => 'A button that sends the reader to a random entry — optionally of a chosen kind.',
                'settings' => [
                    'label' => ['type' => 'text', 'label' => 'Button label', 'default' => 'Show me something'],
                    'kinds' => ['type' => 'kinds', 'label' => 'From', 'default' => [], 'groups' => self::kindGroups()],
                    'style' => ['type' => 'select', 'label' => 'Style', 'default' => 'solid', 'options' => ['solid' => 'Solid', 'outline' => 'Outline']],
                ],
                'classes' => [['selector' => ':root', 'label' => 'The button']],
            ],
            'reusable' => [
                'label' => 'Reusable block',
                'hint' => 'Insert a shared block set — edit it once and every template using it updates.',
                'settings' => [
                    'refId' => ['type' => 'reusable-ref', 'label' => 'Reusable block', 'default' => null],
                ],
                'classes' => [['selector' => ':root', 'label' => 'The block']],
            ],
            'reference' => [
                'label' => 'Reference',
                'hint' => 'Embed a compendium entry — a stat block, spell or magic item.',
                'settings' => [
                    'refId' => ['type' => 'reference', 'label' => 'Compendium entry', 'default' => null],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The reference block'],
                    ['selector' => '.monster, .spell, .item', 'label' => 'The embedded card'],
                ],
            ],
            'columns' => [
                'label' => 'Columns',
                'hint' => 'Split a row into 2–3 columns, then drop blocks into each.',
                'settings' => [
                    'count' => ['type' => 'select', 'label' => 'Columns', 'default' => 2, 'options' => [2 => '2', 3 => '3']],
                ],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The columns row'],
                    ['selector' => '.wb-col', 'label' => 'Each column'],
                    ['selector' => '.wb-col:nth-child(2)', 'label' => 'The 2nd column'],
                ],
            ],
            'divider' => [
                'label' => 'Divider',
                'hint' => 'A thin horizontal rule.',
                'settings' => [],
                'classes' => [
                    ['selector' => ':root', 'label' => 'The rule'],
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function typeKeys(string $target = 'entry'): array
    {
        return array_keys(self::types($target));
    }

    /**
     * One-click starting layouts for a target, each a named, sanitised block list the builder can drop
     * onto a blank canvas.
     *
     * @return list<array{key: string, label: string, description: string, blocks: list<array<string, mixed>>}>
     */
    public static function presets(string $target = 'entry'): array
    {
        $presets = match ($target) {
            'home' => self::homePresets(),
            'archive' => self::archivePresets(),
            'block' => [],
            default => self::entryPresets(),
        };

        return collect($presets)
            ->map(fn (array $preset): array => [
                'key' => $preset['key'],
                'label' => $preset['label'],
                'description' => $preset['description'],
                'blocks' => self::sanitise($preset['blocks'], true, $target),
            ])
            ->all();
    }

    /** @return list<array{key: string, label: string, description: string, blocks: list<array<string, mixed>>}> */
    private static function entryPresets(): array
    {
        return [
            [
                'key' => 'classic', 'label' => 'Classic', 'description' => 'Banner, title, quick facts, content and related.',
                'blocks' => [
                    ['type' => 'banner'], ['type' => 'header'], ['type' => 'facts'], ['type' => 'content'], ['type' => 'related'],
                ],
            ],
            [
                'key' => 'gazetteer', 'label' => 'Gazetteer', 'description' => 'Quick facts beside the content in two columns.',
                'blocks' => [
                    ['type' => 'header'],
                    ['type' => 'columns', 'settings' => ['count' => 2, 'cols' => [
                        [['type' => 'facts']],
                        [['type' => 'content']],
                    ]]],
                    ['type' => 'related'],
                ],
            ],
            [
                'key' => 'character', 'label' => 'Character sheet', 'description' => 'Facts and stat tiles beside the story, then connections.',
                'blocks' => [
                    ['type' => 'header'],
                    ['type' => 'columns', 'settings' => ['count' => 2, 'cols' => [
                        [['type' => 'facts'], ['type' => 'stats']],
                        [['type' => 'content']],
                    ]]],
                    ['type' => 'connections'],
                ],
            ],
            [
                'key' => 'lore', 'label' => 'Lore page', 'description' => 'A pull-quote, a table of contents and prose.',
                'blocks' => [
                    ['type' => 'header'], ['type' => 'quote'], ['type' => 'toc'], ['type' => 'content'],
                ],
            ],
        ];
    }

    /** @return list<array{key: string, label: string, description: string, blocks: list<array<string, mixed>>}> */
    private static function homePresets(): array
    {
        return [
            [
                'key' => 'showcase', 'label' => 'Showcase', 'description' => 'Hero, featured picks, section doors and latest entries.',
                'blocks' => [['type' => 'hero'], ['type' => 'featured'], ['type' => 'sections'], ['type' => 'recent']],
            ],
            [
                'key' => 'hub', 'label' => 'Campaign hub', 'description' => 'Hero, a next-session countdown, campaigns and recaps.',
                'blocks' => [['type' => 'hero'], ['type' => 'nextsession'], ['type' => 'spotlight'], ['type' => 'recaps'], ['type' => 'recent']],
            ],
            [
                'key' => 'minimal', 'label' => 'Minimal', 'description' => 'Hero, a search bar and the latest entries.',
                'blocks' => [['type' => 'hero'], ['type' => 'search'], ['type' => 'recent']],
            ],
        ];
    }

    /** @return list<array{key: string, label: string, description: string, blocks: list<array<string, mixed>>}> */
    private static function archivePresets(): array
    {
        return [
            [
                'key' => 'gallery', 'label' => 'Gallery', 'description' => 'Heading, filter bar and a card grid.',
                'blocks' => [['type' => 'heading'], ['type' => 'filter'], ['type' => 'grid']],
            ],
            [
                'key' => 'index', 'label' => 'A–Z index', 'description' => 'Heading, filter chips and an alphabetical index.',
                'blocks' => [['type' => 'heading'], ['type' => 'facets'], ['type' => 'index']],
            ],
            [
                'key' => 'table', 'label' => 'Table', 'description' => 'Heading, filter bar and a compact table.',
                'blocks' => [['type' => 'heading'], ['type' => 'filter'], ['type' => 'table']],
            ],
        ];
    }

    /**
     * The entry kinds an Entries block can filter by, grouped by section (with an "Other" bucket for any
     * kind no section claims), so the builder can show a grouped multi-select.
     *
     * @return list<array{label: string, kinds: list<array{value: string, label: string}>}>
     */
    private static function kindGroups(): array
    {
        $grouped = [];
        $claimed = [];

        foreach (Sections::SECTIONS as $section) {
            $kinds = [];
            foreach ($section['kinds'] as $kind) {
                $kinds[] = ['value' => $kind, 'label' => Sections::kindLabel($kind)];
                $claimed[] = $kind;
            }
            $grouped[] = ['label' => $section['label'], 'kinds' => $kinds];
        }

        $other = array_values(array_diff(Sections::KINDS, $claimed));
        if ($other !== []) {
            $grouped[] = [
                'label' => 'Other',
                'kinds' => array_map(fn (string $kind): array => ['value' => $kind, 'label' => Sections::kindLabel($kind)], $other),
            ];
        }

        return $grouped;
    }

    /**
     * The default settings for a block type (schema defaults).
     *
     * @return array<string, mixed>
     */
    public static function defaults(string $type, string $target = 'entry'): array
    {
        return collect(self::types($target)[$type]['settings'] ?? [])
            ->map(fn (array $schema): mixed => $schema['default'] ?? null)
            ->all();
    }

    /**
     * Resolve a layout into a clean ordered block list: the stored blocks when present, otherwise a
     * migration of the legacy {facts, width, banner, fields} shape (or the default set for a new one).
     *
     * @param  array<string, mixed>|null  $layout
     * @return list<array{id: string, type: string, settings: array<string, mixed>, css: string}>
     */
    public static function normalise(?array $layout, string $kind = '', string $target = 'entry'): array
    {
        $blocks = $layout['blocks'] ?? null;

        // Home and archive targets have no legacy shape — just sanitise (or start fresh).
        if ($target !== 'entry') {
            return is_array($blocks) && $blocks !== []
                ? self::sanitise($blocks, true, $target)
                : self::starter($target);
        }

        if (is_array($blocks) && $blocks !== []) {
            return self::sanitise($blocks);
        }

        return self::fromLegacy($layout ?? [], $kind);
    }

    /** The block list a brand-new template starts from. */
    public static function starter(string $target = 'entry', string $kind = 'location'): array
    {
        return match ($target) {
            'home' => self::homeStarter(),
            'archive' => self::archiveStarter(),
            'block' => [self::block('text', [], 'block')],
            default => self::fromLegacy([], $kind),
        };
    }

    /** The default home page: hero, featured, section doors, latest entries. */
    private static function homeStarter(): array
    {
        return [
            self::block('hero', [], 'home'),
            self::block('featured', [], 'home'),
            self::block('sections', [], 'home'),
            self::block('recent', [], 'home'),
        ];
    }

    /** The default archive page: heading then the entry grid. */
    private static function archiveStarter(): array
    {
        return [
            self::block('heading', [], 'archive'),
            self::block('grid', [], 'archive'),
        ];
    }

    /**
     * Sanitise a repeatable list of string-keyed rows (stat tiles, accordion panels), trimming each
     * value and capping the row count.
     *
     * @param  list<string>  $keys
     * @return list<array<string, string>>
     */
    private static function sanitiseRows(mixed $rows, array $keys, int $maxLength, int $maxRows = 24): array
    {
        return collect(is_array($rows) ? $rows : [])
            ->map(function (mixed $row) use ($keys, $maxLength): array {
                $clean = [];
                foreach ($keys as $key) {
                    $clean[$key] = mb_substr(trim((string) data_get($row, $key, '')), 0, $maxLength);
                }

                return $clean;
            })
            ->take($maxRows)
            ->values()
            ->all();
    }

    /**
     * Trim, cap and de-blank a list of URLs (gallery images).
     *
     * @return list<string>
     */
    private static function sanitiseUrls(mixed $urls): array
    {
        return collect(is_array($urls) ? $urls : [])
            ->map(fn (mixed $url): string => mb_substr(trim((string) $url), 0, 2000))
            ->filter()->take(48)->values()->all();
    }

    /**
     * Coerce a list of entry ids to unique ints (linked-entries, comparison).
     *
     * @return list<int>
     */
    private static function sanitiseIds(mixed $ids): array
    {
        return collect(is_array($ids) ? $ids : [])
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()->take(24)->values()->all();
    }

    /**
     * A block's optional visibility rule ({ field, op, value }), or null. The op must be one we evaluate;
     * an empty field means "no rule" (always shown).
     *
     * @return array{field: string, op: string, value: string}|null
     */
    private static function sanitiseVisibleIf(mixed $rule): ?array
    {
        if (! is_array($rule)) {
            return null;
        }

        $field = mb_substr(trim((string) ($rule['field'] ?? '')), 0, 64);
        if ($field === '') {
            return null;
        }

        $op = in_array($rule['op'] ?? '', ['set', 'unset', 'eq', 'ne'], true) ? $rule['op'] : 'set';

        return [
            'field' => $field,
            'op' => $op,
            'value' => mb_substr(trim((string) ($rule['value'] ?? '')), 0, 200),
        ];
    }

    /**
     * The kind filter for an Entries block: keep only real kinds, and migrate the legacy single `kind`.
     *
     * @param  array<string, mixed>  $incoming
     * @return list<string>
     */
    private static function sanitiseKinds(array $incoming): array
    {
        $raw = $incoming['kinds'] ?? (filled($incoming['kind'] ?? null) ? [$incoming['kind']] : []);

        return collect(is_array($raw) ? $raw : [])
            ->filter(fn (mixed $kind): bool => in_array($kind, Sections::KINDS, true))
            ->unique()->values()->all();
    }

    /**
     * Every compendium item id referenced by a reference block anywhere in the layout (including inside
     * columns), so the reader can resolve them into embeds.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<int>
     */
    public static function referenceIds(array $blocks): array
    {
        return self::collectIds($blocks, 'reference', 'refId');
    }

    /**
     * Every document id referenced by a "linked entries" block anywhere in the layout, so the reader
     * can resolve them into cards.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<int>
     */
    public static function linkedEntryIds(array $blocks): array
    {
        return self::collectIds($blocks, 'linked', 'ids');
    }

    /**
     * Every document id compared by a "comparison" block anywhere in the layout, so the reader can
     * resolve their facts.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<int>
     */
    public static function comparisonEntryIds(array $blocks): array
    {
        return self::collectIds($blocks, 'comparison', 'ids');
    }

    /**
     * Every map id referenced by a "map" block anywhere in the layout (including inside columns), so the
     * reader can resolve them into MapViewer payloads.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<int>
     */
    public static function mapIds(array $blocks): array
    {
        return self::collectIds($blocks, 'map', 'mapId');
    }

    /**
     * Every reusable-block id referenced by a "reusable" block anywhere in the layout, so the reader can
     * resolve them into their stored block sets.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<int>
     */
    public static function reusableIds(array $blocks): array
    {
        return self::collectIds($blocks, 'reusable', 'refId');
    }

    /**
     * Null out every world-scoped id reference (compendium, map, linked entries, comparison entries,
     * reusable blocks) in a block list, recursing into columns and repeaters. Used when importing a
     * template into a *different* world, where those ids would otherwise dangle — or worse, resolve to an
     * unrelated item that happens to share the id.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function stripReferences(array $blocks): array
    {
        return array_map(function (array $block): array {
            $type = $block['type'] ?? '';

            if ($type === 'reference' || $type === 'reusable') {
                $block['settings']['refId'] = null;
            }
            if ($type === 'map') {
                $block['settings']['mapId'] = null;
            }
            if ($type === 'linked' || $type === 'comparison') {
                $block['settings']['ids'] = [];
            }
            if ($type === 'columns') {
                $block['settings']['cols'] = array_map(
                    fn (array $column): array => self::stripReferences($column),
                    $block['settings']['cols'] ?? [],
                );
            }
            if ($type === 'repeater') {
                $block['settings']['blocks'] = self::stripReferences($block['settings']['blocks'] ?? []);
            }

            return $block;
        }, $blocks);
    }

    /**
     * The structured-field keys used as extra columns by "table" blocks anywhere in an archive layout
     * (walking into columns), so the reader can resolve just those field values for its cards.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<string>
     */
    public static function fieldColumnKeys(array $blocks): array
    {
        $keys = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'table') {
                foreach ((array) ($block['settings']['fields'] ?? []) as $key) {
                    $keys[] = (string) $key;
                }
            }
            if (($block['type'] ?? '') === 'columns') {
                foreach ($block['settings']['cols'] ?? [] as $column) {
                    $keys = array_merge($keys, self::fieldColumnKeys($column));
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Collect every numeric id held under `settings.$key` by blocks of `$type`, walking into columns.
     * Works for both scalar settings (refId, mapId) and id lists (linked/comparison `ids`).
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<int>
     */
    private static function collectIds(array $blocks, string $type, string $key): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === $type) {
                foreach ((array) ($block['settings'][$key] ?? []) as $id) {
                    if (is_numeric($id)) {
                        $ids[] = (int) $id;
                    }
                }
            }
            if (($block['type'] ?? '') === 'columns') {
                foreach ($block['settings']['cols'] ?? [] as $column) {
                    $ids = array_merge($ids, self::collectIds($column, $type, $key));
                }
            }
            if (($block['type'] ?? '') === 'repeater') {
                $ids = array_merge($ids, self::collectIds($block['settings']['blocks'] ?? [], $type, $key));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Bridge a block list back to the reader's existing {facts, width, banner, fields} knobs, so today's
     * reader honours the meaningful settings while full free-order block rendering is built.
     *
     * @param  list<array{id: string, type: string, settings: array<string, mixed>, css: string}>  $blocks
     * @return array{facts: string, width: string, banner: string, fields: list<string>}
     */
    public static function toLegacyLayout(array $blocks): array
    {
        $byType = collect($blocks)->keyBy('type');
        $facts = $byType->get('facts');

        return [
            'banner' => $byType->has('banner') ? 'show' : 'hide',
            'facts' => $facts === null
                ? 'off'
                : (($facts['settings']['style'] ?? 'card') === 'band' ? 'top' : 'sidebar'),
            'width' => (($byType->get('content')['settings']['width'] ?? 'normal') === 'wide') ? 'wide' : 'normal',
            'fields' => is_array($facts['settings']['fields'] ?? null) ? array_values($facts['settings']['fields']) : [],
        ];
    }

    /**
     * Keep only well-formed blocks of known types, merging each block's settings over the type defaults
     * so unknown keys are dropped and missing ones filled.
     *
     * @param  array<int, mixed>  $blocks
     * @param  bool  $allowColumns  columns can hold other blocks, but not further columns (one level deep)
     * @return list<array{id: string, type: string, settings: array<string, mixed>, css: string}>
     */
    public static function sanitise(array $blocks, bool $allowColumns = true, string $target = 'entry'): array
    {
        $types = self::types($target);

        return collect($blocks)
            ->map(function (mixed $block) use ($types, $allowColumns, $target): ?array {
                $type = is_array($block) ? (string) ($block['type'] ?? '') : '';
                // Containers (columns, repeater) hold other blocks but can't nest inside one another, so
                // they're only allowed at the top level (allowColumns).
                if (! isset($types[$type]) || (in_array($type, ['columns', 'repeater'], true) && ! $allowColumns)) {
                    return null;
                }

                // Sanitise each setting by its schema type — the array/id/row types get bounded and typed,
                // scalars pass through. This is schema-driven so a new block reusing a known control type
                // (entries, kinds, panes, faq, …) is sanitised without a new special-case.
                $incoming = is_array($block['settings'] ?? null) ? $block['settings'] : [];
                $settings = [];
                foreach ($types[$type]['settings'] as $key => $schema) {
                    $raw = array_key_exists($key, $incoming) ? $incoming[$key] : ($schema['default'] ?? null);
                    $settings[$key] = match ($schema['type']) {
                        'stats' => self::sanitiseRows($raw, ['label', 'value'], 500),
                        'panes' => self::sanitiseRows($raw, ['title', 'markdown'], 5000),
                        'faq' => self::sanitiseRows($raw, ['question', 'answer'], 5000),
                        'events' => self::sanitiseRows($raw, ['when', 'title', 'detail'], 1000),
                        'images' => self::sanitiseUrls($raw),
                        'entries' => self::sanitiseIds($raw),
                        'kinds' => self::sanitiseKinds($incoming),
                        'reference', 'map', 'reusable-ref' => is_numeric($raw) ? (int) $raw : null,
                        'fields' => is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [],
                        default => $raw,
                    };
                }

                // A columns block splits into `count` columns, each holding its own child blocks (no further
                // columns, so nesting is exactly one level). `cols` isn't a schema field (it holds child
                // blocks, edited on the canvas), so read it straight from the incoming settings.
                if ($type === 'columns') {
                    $count = in_array((int) ($settings['count'] ?? 2), [2, 3], true) ? (int) $settings['count'] : 2;
                    $rawCols = is_array($incoming['cols'] ?? null) ? array_values($incoming['cols']) : [];
                    $cols = [];
                    for ($column = 0; $column < $count; $column++) {
                        $childBlocks = is_array($rawCols[$column] ?? null) ? $rawCols[$column] : [];
                        $cols[] = array_slice(self::sanitise($childBlocks, false, $target), 0, 12);
                    }
                    $settings['count'] = $count;
                    $settings['cols'] = $cols;
                }

                // A repeater holds a single list of child blocks (no further containers), rendered once per
                // source item. Like `cols`, `blocks` isn't a schema field — read it from the incoming settings.
                if ($type === 'repeater') {
                    $rawBlocks = is_array($incoming['blocks'] ?? null) ? array_values($incoming['blocks']) : [];
                    $settings['blocks'] = array_slice(self::sanitise($rawBlocks, false, $target), 0, 12);
                }

                $id = is_array($block) ? (string) ($block['id'] ?? '') : '';
                $css = is_array($block) && is_string($block['css'] ?? null) ? mb_substr(trim($block['css']), 0, 5000) : '';

                $device = is_array($block) ? ($block['device'] ?? 'all') : 'all';

                return [
                    'id' => $id !== '' ? $id : $type.'-'.Str::lower(Str::random(6)),
                    'type' => $type,
                    'settings' => $settings,
                    'css' => $css,
                    'visibleIf' => self::sanitiseVisibleIf(is_array($block) ? ($block['visibleIf'] ?? null) : null),
                    'device' => in_array($device, ['desktop', 'mobile'], true) ? $device : 'all',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Migrate a legacy layout (or an empty one) into the equivalent block list.
     *
     * @param  array<string, mixed>  $layout
     * @return list<array{id: string, type: string, settings: array<string, mixed>, css: string}>
     */
    private static function fromLegacy(array $layout, string $kind): array
    {
        $blocks = [];

        if (($layout['banner'] ?? 'auto') !== 'hide') {
            $blocks[] = self::block('banner');
        }

        $blocks[] = self::block('header');

        if (($layout['facts'] ?? 'sidebar') !== 'off') {
            $blocks[] = self::block('facts', [
                'style' => ($layout['facts'] ?? 'sidebar') === 'top' ? 'band' : 'card',
                'fields' => is_array($layout['fields'] ?? null) ? array_values($layout['fields']) : [],
            ]);
        }

        $blocks[] = self::block('content', ['width' => ($layout['width'] ?? 'normal') === 'wide' ? 'wide' : 'normal']);
        $blocks[] = self::block('related');

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{id: string, type: string, settings: array<string, mixed>, css: string}
     */
    private static function block(string $type, array $overrides = [], string $target = 'entry'): array
    {
        return [
            'id' => $type,
            'type' => $type,
            'settings' => [...self::defaults($type, $target), ...$overrides],
            'css' => '',
        ];
    }
}
