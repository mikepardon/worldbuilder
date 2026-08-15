<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\Media;
use App\Models\User;
use App\Models\World;
use App\Models\WorldBlock;
use App\Support\Statblock;
use App\Support\TemplateBlocks;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds the read-only "Sandbox" world — a fully-realised demo campaign ("Saltmere & the Sundered
 * Coast") that new GMs can view from a player's perspective and then clone into their own editable
 * world. Content lives in database/seeders/data/sandbox/*.php so it can be authored independently of the
 * seeding logic. Idempotent: it skips if a sandbox campaign already exists.
 */
class SandboxWorldSeeder extends Seeder
{
    /** Where each capital sits on the map of Aeria, as image percentages: [x, y, label]. */
    private const PLACES = [
        'arcmond' => [80, 48, 'Arcmond'],
        'ankmier' => [52, 50, 'Ankmier'],
        'grimwrath' => [40, 30, 'Grimwrath'],
        'phendor' => [30, 58, 'Phendor'],
        'niswick' => [60, 32, 'Niswick'],
        'shendrift' => [50, 82, 'Shendrift'],
        'gloomgrove' => [26, 76, 'Gloomgrove'],
        'stormhaven' => [90, 24, 'Stormhaven'],
        'ironvein-fortress' => [92, 15, 'Ironvein Fortress'],
    ];

    /** Enemy tokens for the demo battle scene — a Gloomgrove ambush: [compendium slug, x%, y%]. */
    private const ENCOUNTER = [
        ['grove-goblin', 34, 38],
        ['grove-goblin', 40, 52],
        ['grove-goblin', 30, 60],
        ['gloom-lurker', 62, 46],
        ['bog-ghast', 71, 58],
    ];

    /** Directed links that give the "web" graph its shape: [from slug, to slug, relationship]. */
    private const LINKS = [
        ['arcmond', 'aeria', 'part_of'], ['ankmier', 'aeria', 'part_of'], ['grimwrath', 'aeria', 'part_of'],
        ['phendor', 'aeria', 'part_of'], ['niswick', 'aeria', 'part_of'], ['shendrift', 'aeria', 'part_of'],
        ['gloomgrove', 'aeria', 'part_of'],
        ['lalder', 'the-ankmier-council', 'member_of'], ['vivian', 'the-ankmier-council', 'member_of'],
        ['stout', 'the-ankmier-council', 'member_of'], ['the-ankmier-council', 'ankmier', 'located_in'],
        ['niswick', 'stormhaven', 'owns'], ['ironvein-fortress', 'stormhaven', 'located_in'],
        ['gorlak-ironfist', 'ironvein-fortress', 'located_in'], ['vark-dregan', 'ironvein-fortress', 'located_in'],
        ['felix-bray', 'ironvein-fortress', 'located_in'], ['verik-blackhand', 'ironvein-fortress', 'located_in'],
        ['the-church-of-grimwrath', 'grimwrath', 'located_in'], ['st-ulrichs-cathedral', 'grimwrath', 'located_in'],
        ['university-of-oxdohr', 'phendor', 'located_in'], ['the-academic-coalition', 'phendor', 'located_in'],
        ['the-generals-of-arcmond', 'arcmond', 'located_in'], ['the-niswick-nobility', 'niswick', 'located_in'],
    ];

    public function run(): void
    {
        if (World::where('is_sandbox', true)->exists()) {
            return;
        }

        $owner = User::firstOrCreate(
            ['email' => 'sandbox@worldbuilder.test'],
            ['name' => 'WorldBuilder', 'password' => Hash::make(Str::random(40)), 'email_verified_at' => now()],
        );

        $world = $owner->worlds()->create([
            'slug' => 'glieda-sandbox',
            'name' => 'Glieda',
            'description' => 'The world of Glieda and its continent of Aeria — seven capitals, an island prison, and a whole cast of rulers, scholars and inmates. The demo world you can explore and copy.',
            'setting' => 'Aeria, one of the six continents of Glieda: dwarven shipyards in the east, an anti-magic theocracy in the mountains, a desert of scholars, and the inescapable Ironvein Fortress out on the island of Stormhaven.',
            'visibility' => 'public',
            'is_sandbox' => true,
        ]);
        // The World::created hook made a "Main Campaign" — it holds the play (rooms, sessions, players).
        $campaign = $world->campaigns()->firstOrFail();

        $compendium = $this->seedCompendium($world, $owner);
        $documents = $this->seedDocuments($world, $owner, $compendium['ids']);
        $this->seedLinks($world, $documents);
        $this->seedMap($world, $owner, $documents);
        $this->seedSessions($campaign);
        $this->seedBattle($campaign, $world, $owner, $compendium);

        // Features added since the original demo: per-world custom fields, section-door images, roll
        // tables, a calendar, a bloodline family tree, a reusable-block/template library, and the richer
        // per-entry metadata (accent, TOC, comments, related links, references, scheduled publish).
        $this->seedWorldFields($world);
        $this->seedSectionImages($world, $owner);
        $this->seedRollTables($world);
        $this->seedCalendar($world);
        $this->seedBloodline($world, $owner, $documents);
        $this->seedTemplateLibrary($world);
        $this->seedEntryPolish($world, $owner, $documents);
    }

    private function seedSessions(Campaign $campaign): void
    {
        foreach (array_values($this->data('sessions')) as $index => $entry) {
            $campaign->sessions()->create([
                'title' => $entry['title'],
                'slug' => $entry['slug'] ?? null,
                'summary' => $entry['summary'] ?? null,
                'body' => $entry['content'] ?? null,
                'sort' => $index,
                'is_private' => $entry['is_private'] ?? false,
            ]);
        }
    }

    /**
     * @return array{ids: array<string, int>, monsters: array<string, array{id: int, name: string, hp: int, ac: int}>}
     */
    private function seedCompendium(World $world, User $owner): array
    {
        $ids = [];
        $monsters = [];

        foreach ($this->data('monsters') as $monster) {
            $block = Statblock::fromOpen5e($monster['open5e']);
            $item = $world->compendiumItems()->create([
                'user_id' => $owner->id,
                'item_type' => 'monster',
                'slug' => $monster['slug'],
                'name' => $monster['name'],
                'summary' => $monster['summary'],
                'provider' => 'custom',
                'data' => $monster['open5e'],
                'fields' => ['block' => $block],
                'document' => Statblock::toMarkdown($block ?? Statblock::empty(), $monster['name']),
                'is_private' => $monster['is_private'] ?? false,
                'is_active' => true,
            ]);
            $ids[$monster['slug']] = $item->id;
            $monsters[$monster['slug']] = [
                'id' => $item->id,
                'name' => $monster['name'],
                'hp' => (int) ($monster['open5e']['hit_points'] ?? 10),
                'ac' => (int) ($monster['open5e']['armor_class'] ?? 10),
            ];
        }

        $files = [
            'spell' => 'spells', 'magicitem' => 'magicitems', 'equipment' => 'equipment',
            'condition' => 'conditions', 'race' => 'races', 'feat' => 'feats',
        ];
        foreach ($files as $type => $file) {
            foreach ($this->data($file) as $entry) {
                $item = $world->compendiumItems()->create([
                    'user_id' => $owner->id,
                    'item_type' => $type,
                    'slug' => $entry['slug'],
                    'name' => $entry['name'],
                    'summary' => $entry['summary'],
                    'provider' => 'custom',
                    'document' => $entry['document'],
                    'is_private' => $entry['is_private'] ?? false,
                    'tags' => $entry['tags'] ?? [],
                    'is_active' => true,
                ]);
                $ids[$entry['slug']] = $item->id;
            }
        }

        return ['ids' => $ids, 'monsters' => $monsters];
    }

    /**
     * @param  array<string, int>  $compendiumIds
     * @return array<string, int> document slug => id
     */
    private function seedDocuments(World $world, User $owner, array $compendiumIds): array
    {
        $slugs = [];
        foreach (['locations', 'people', 'lore', 'timelines'] as $file) {
            foreach ($this->data($file) as $entry) {
                $slug = $entry['slug'] ?? Str::slug($entry['title']);
                $document = $world->documents()->create([
                    'user_id' => $owner->id,
                    'title' => $entry['title'],
                    'slug' => $slug,
                    'kind' => $entry['kind'],
                    'summary' => $entry['summary'] ?? null,
                    // Resolve {{MONSTER:slug}} placeholders to real {{monster=id}} stat-block embeds.
                    'content' => $this->resolveEmbeds($entry['content'], $compendiumIds),
                    'data' => $entry['data'] ?? [],
                    'is_private' => $entry['is_private'] ?? false,
                    'tags' => $entry['tags'] ?? [],
                ]);
                $slugs[$slug] = $document->id;
            }
        }

        return $slugs;
    }

    /** @param  array<string, int>  $documents */
    private function seedLinks(World $world, array $documents): void
    {
        foreach (self::LINKS as [$from, $to, $relationship]) {
            if (isset($documents[$from], $documents[$to])) {
                $world->documentLinks()->create([
                    'from_document_id' => $documents[$from],
                    'to_document_id' => $documents[$to],
                    'relationship' => $relationship,
                    'source' => 'manual',
                ]);
            }
        }
    }

    /** @param  array<string, int>  $documents */
    private function seedMap(World $world, User $owner, array $documents): void
    {
        $map = $world->maps()->create([
            'image_media_id' => $this->storeSvg($world, $owner, 'region', $this->regionSvg())->id,
            'name' => 'Aeria',
            'slug' => 'aeria-map',
            'is_private' => false,
            'sort' => 0,
            'grid_visible' => false,
            'grid_size' => 20,
            'unit_size' => 5,
            'unit' => 'ft',
            'fog_enabled' => false,
        ]);

        foreach (self::PLACES as $slug => [$x, $y, $label]) {
            $map->pins()->create([
                'document_id' => $documents[$slug] ?? null,
                'behavior' => 'article',
                'x' => $x,
                'y' => $y,
                'label' => $label,
            ]);
        }
    }

    /** @param  array{ids: array<string, int>, monsters: array<string, array{id: int, name: string, hp: int, ac: int}>}  $compendium */
    private function seedBattle(Campaign $campaign, World $world, User $owner, array $compendium): void
    {
        $room = $campaign->rooms()->create([
            'created_by' => $owner->id,
            'name' => 'Gloomgrove — Ambush',
            'grid_visible' => true,
            'grid_size' => 20,
            'unit_size' => 5,
            'unit' => 'ft',
            'fog_enabled' => false,
            'round' => 1,
            'players_see_tracker' => true,
        ]);

        // The room auto-created its first scene; dress it as the tavern floor.
        $scene = $room->activeScene;
        $scene?->update([
            'name' => 'The Gloomgrove Deeps',
            'image_media_id' => $this->storeSvg($world, $owner, 'battle', $this->battleSvg())->id,
        ]);

        foreach (self::ENCOUNTER as [$slug, $x, $y]) {
            $monster = $compendium['monsters'][$slug] ?? null;
            if ($monster === null) {
                continue;
            }
            $room->tokens()->create([
                'scene_id' => $scene?->id,
                'compendium_item_id' => $monster['id'],
                'kind' => 'monster',
                'label' => $monster['name'],
                'x' => $x,
                'y' => $y,
                'size' => 1,
                'color' => '#b4443a',
                'hp' => $monster['hp'],
                'max_hp' => $monster['hp'],
                'ac' => $monster['ac'],
                'in_tracker' => true,
            ]);
        }
    }

    /**
     * Load a content data file (a plain PHP array).
     *
     * @return list<array<string, mixed>>
     */
    private function data(string $file): array
    {
        return require database_path("seeders/data/sandbox/{$file}.php");
    }

    /** @param  array<string, int>  $compendiumIds */
    private function resolveEmbeds(string $content, array $compendiumIds): string
    {
        return preg_replace_callback('/\{\{MONSTER:([a-z0-9-]+)\}\}/', function (array $matches) use ($compendiumIds): string {
            $id = $compendiumIds[$matches[1]] ?? null;

            return $id === null ? $matches[0] : "{{monster={$id}}}";
        }, $content) ?? $content;
    }

    private function storeSvg(World $world, User $owner, string $name, string $svg): Media
    {
        // Stored on the media disk under the public `media/` prefix, so demo images resolve the same on
        // the local public disk and on a production S3 bucket (whose public-read policy covers media/*).
        $disk = config('media.disk');
        $path = "media/sandbox/{$name}.svg";
        Storage::disk($disk)->put($path, $svg);

        return Media::create([
            'user_id' => $owner->id,
            'world_id' => $world->id,
            'disk' => $disk,
            'path' => $path,
            'filename' => "{$name}.svg",
            'mime' => 'image/svg+xml',
            'size' => strlen($svg),
        ]);
    }

    /** A stylised region map of the Sundered Coast with a marker + label at each location. */
    private function regionSvg(): string
    {
        $w = 1200;
        $h = 800;
        $markers = '';
        foreach (self::PLACES as $slug => [$x, $y, $label]) {
            $px = round($x / 100 * $w);
            $py = round($y / 100 * $h);
            $anchor = $px > $w * 0.7 ? 'end' : 'start';
            $dx = $anchor === 'end' ? -14 : 14;
            $markers .= <<<SVG
                <g>
                    <circle cx="{$px}" cy="{$py}" r="7" fill="#d8a94a" stroke="#1b140a" stroke-width="2"/>
                    <text x="{$px}" y="{$py}" dx="{$dx}" dy="5" font-family="Georgia, serif" font-size="20" fill="#f3ead2" text-anchor="{$anchor}" style="paint-order:stroke;stroke:#0b0d10;stroke-width:4px;">{$label}</text>
                </g>
            SVG;
        }

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$w} {$h}" width="{$w}" height="{$h}">
            <defs>
                <radialGradient id="sea" cx="50%" cy="60%" r="80%">
                    <stop offset="0%" stop-color="#1c3a45"/>
                    <stop offset="100%" stop-color="#0c1a20"/>
                </radialGradient>
                <linearGradient id="land" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#3f4a34"/>
                    <stop offset="100%" stop-color="#2b3326"/>
                </linearGradient>
            </defs>
            <rect width="{$w}" height="{$h}" fill="url(#sea)"/>
            <g opacity="0.5" stroke="#2a4a55" stroke-width="1" fill="none">
                <ellipse cx="600" cy="480" rx="520" ry="300"/>
                <ellipse cx="600" cy="480" rx="400" ry="220"/>
                <ellipse cx="600" cy="480" rx="270" ry="150"/>
            </g>
            <path d="M 300 780 C 360 560, 520 520, 560 380 C 600 250, 760 250, 820 360 C 900 500, 840 700, 900 800 Z"
                  fill="url(#land)" stroke="#5a6b47" stroke-width="3" opacity="0.95"/>
            <path d="M 780 460 C 900 440, 980 520, 960 620 C 940 700, 840 700, 800 620 Z"
                  fill="#33402b" stroke="#4a5a3a" stroke-width="2" opacity="0.85"/>
            <g fill="#3a4a3a" stroke="#4a5a3a" stroke-width="2" opacity="0.9">
                <circle cx="205" cy="176" r="34"/>
                <circle cx="150" cy="230" r="20"/>
                <circle cx="255" cy="235" r="16"/>
            </g>
            <text x="600" y="60" font-family="Georgia, serif" font-size="42" fill="#d8a94a" text-anchor="middle" letter-spacing="6" style="paint-order:stroke;stroke:#0b0d10;stroke-width:5px;">AERIA</text>
            <g transform="translate(1090,700)" fill="#d8a94a" opacity="0.8">
                <circle r="34" fill="none" stroke="#d8a94a" stroke-width="2"/>
                <path d="M 0 -30 L 7 0 L 0 30 L -7 0 Z" fill="#d8a94a"/>
                <text y="-40" font-family="Georgia, serif" font-size="16" text-anchor="middle">N</text>
            </g>
            {$markers}
        </svg>
        SVG;
    }

    /** A simple top-down battle map of the tavern floor for the demo encounter. */
    private function battleSvg(): string
    {
        $w = 1000;
        $h = 700;

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$w} {$h}" width="{$w}" height="{$h}">
            <defs>
                <linearGradient id="planks" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#4a3a28"/>
                    <stop offset="100%" stop-color="#3a2c1e"/>
                </linearGradient>
            </defs>
            <rect width="{$w}" height="{$h}" fill="url(#planks)"/>
            <g stroke="#2c2016" stroke-width="2" opacity="0.6">
                <line x1="0" y1="90" x2="1000" y2="90"/>
                <line x1="0" y1="180" x2="1000" y2="180"/>
                <line x1="0" y1="270" x2="1000" y2="270"/>
                <line x1="0" y1="360" x2="1000" y2="360"/>
                <line x1="0" y1="450" x2="1000" y2="450"/>
                <line x1="0" y1="540" x2="1000" y2="540"/>
                <line x1="0" y1="630" x2="1000" y2="630"/>
            </g>
            <!-- Sea spilling through the doorway (bottom) -->
            <path d="M 0 640 Q 250 600 500 640 T 1000 640 L 1000 700 L 0 700 Z" fill="#1c3a45" opacity="0.85"/>
            <!-- The bar along the top -->
            <rect x="60" y="40" width="500" height="70" rx="8" fill="#5a4630" stroke="#2c2016" stroke-width="3"/>
            <text x="310" y="85" font-family="Georgia, serif" font-size="26" fill="#d8a94a" text-anchor="middle" opacity="0.8">GLOOMGROVE</text>
            <!-- Round tables -->
            <g fill="#5a4630" stroke="#2c2016" stroke-width="3">
                <circle cx="230" cy="300" r="55"/>
                <circle cx="620" cy="260" r="55"/>
                <circle cx="760" cy="470" r="55"/>
                <circle cx="330" cy="520" r="55"/>
            </g>
            <!-- Crates / barrels -->
            <g fill="#6a5236" stroke="#2c2016" stroke-width="3">
                <rect x="880" y="120" width="70" height="70" rx="6"/>
                <rect x="900" y="210" width="60" height="60" rx="6"/>
                <circle cx="120" cy="470" r="28"/>
                <circle cx="120" cy="540" r="28"/>
            </g>
        </svg>
        SVG;
    }

    /** Per-world custom quick-facts fields: a select, a multi-value field, and a reference relation. */
    private function seedWorldFields(World $world): void
    {
        $fields = [
            ['key' => 'threat', 'label' => 'Threat level', 'type' => 'select', 'kinds' => ['location'],
                'options' => ['Safe', 'Contested', 'Deadly'], 'sort_order' => 10],
            ['key' => 'exports', 'label' => 'Known for', 'type' => 'text', 'multiple' => true, 'kinds' => ['location'],
                'placeholder' => 'Salt', 'sort_order' => 11],
            ['key' => 'patron', 'label' => 'Patron', 'type' => 'reference', 'kinds' => ['location'],
                'ref_kinds' => ['npc'], 'link_label' => 'Patron of', 'inverse_label' => 'Patron', 'sort_order' => 12],
            ['key' => 'aliases', 'label' => 'Also known as', 'type' => 'text', 'multiple' => true, 'kinds' => ['npc'],
                'placeholder' => 'The Tidewright', 'sort_order' => 10],
        ];

        foreach ($fields as $field) {
            $world->customFields()->create([
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'multiple' => $field['multiple'] ?? false,
                'options' => $field['options'] ?? null,
                'ref_kinds' => $field['ref_kinds'] ?? null,
                'kinds' => $field['kinds'],
                'link_label' => $field['link_label'] ?? null,
                'inverse_label' => $field['inverse_label'] ?? null,
                'required' => false,
                'visible' => true,
                'placeholder' => $field['placeholder'] ?? null,
                'sort_order' => $field['sort_order'],
            ]);
        }

        // The GM's chosen display order for a location's quick facts (custom fields folded in among the defaults).
        $world->update([
            'field_order' => [
                'location' => ['type', 'region', 'ruler', 'population', 'threat', 'exports', 'patron'],
            ],
        ]);
    }

    /** A cover image for each populated section's reader "door", plus a hero banner for the home page. */
    private function seedSectionImages(World $world, User $owner): void
    {
        $sections = [
            'locations' => ['Locations', '#3f6b6f'],
            'people' => ['People', '#6b4f7a'],
            'lore' => ['Lore', '#7a5a3a'],
            'timelines' => ['Timelines', '#3a5a7a'],
        ];

        $images = [];
        foreach ($sections as $slug => [$label, $hue]) {
            $images[$slug] = $this->storeSvg($world, $owner, "section-{$slug}", $this->sectionSvg($label, $hue))->url;
        }

        $banner = $this->storeSvg($world, $owner, 'banner', $this->sectionSvg('Glieda — the Continent of Aeria', '#243a2f'));

        $settings = $world->settings ?? [];
        $settings['section_images'] = $images;
        $settings['reader_theme'] = 'teal';
        $world->update(['settings' => $settings, 'banner_media_id' => $banner->id]);
    }

    /** Two rollable, searchable random tables for the world. */
    private function seedRollTables(World $world): void
    {
        $world->rollTables()->create([
            'name' => 'Rumours in Ankmier',
            'description' => 'Overheard in the markets and taverns of Ankmier. Roll a d20 when the party listens in.',
            'die' => 20,
            'is_private' => false,
            'rows' => [
                ['min' => 1, 'max' => 3, 'result' => 'A caravan out of Phendor arrived a scholar short — and nobody will say which one.'],
                ['min' => 4, 'max' => 6, 'result' => 'The councillor Vom has been seen slumming it down in the Moldy Eye. Twice this week.'],
                ['min' => 7, 'max' => 9, 'result' => 'Grimwrath is offering a bounty for an unregistered mage said to be hiding in the city.'],
                ['min' => 10, 'max' => 12, 'result' => 'The House of Cards is running a game with a very unusual prize: a name off a prison ledger.'],
                ['min' => 13, 'max' => 15, 'result' => "This year's Stormhaven release has already been decided — and it's been bought and paid for."],
                ['min' => 16, 'max' => 18, 'result' => 'Someone is quietly buying up passage on the Ironvein prison ship. All of it.'],
                ['min' => 19, 'max' => 20, 'result' => 'The goblins of Gloomgrove have come to market for the first time in a generation.'],
            ],
        ]);

        $world->rollTables()->create([
            'name' => 'Ironvein complications',
            'description' => 'What goes wrong inside Iron Hell. Roll a d12 when a plan meets the fortress.',
            'die' => 12,
            'is_private' => false,
            'rows' => [
                ['min' => 1, 'max' => 2, 'result' => 'A shift change puts twice the guards in the corridor you needed empty.'],
                ['min' => 3, 'max' => 4, 'result' => 'An anti-magic wristband cannot be removed as quietly as you were promised.'],
                ['min' => 5, 'max' => 6, 'result' => 'Durga Stonegrip wants a much bigger cut — and knows exactly why.'],
                ['min' => 7, 'max' => 8, 'result' => "Felix Bray's hounds have your scent, and they do not tire."],
                ['min' => 9, 'max' => 10, 'result' => 'A solitary inmate offers help you did not ask for, at a price you cannot yet see.'],
                ['min' => 11, 'max' => 12, 'result' => 'An enchanted whistle sounds from the guard tower. Reinforcements are already moving.'],
            ],
        ]);
    }

    /** The world's calendar — its own months, weekdays and moons — with a few dated events. */
    private function seedCalendar(World $world): void
    {
        $calendar = $world->calendars()->create([
            'name' => 'The Aerian Reckoning',
            'months' => [
                ['name' => 'Deepfrost', 'days' => 30],
                ['name' => 'Firstmelt', 'days' => 30],
                ['name' => 'Greenreach', 'days' => 31],
                ['name' => 'Highsun', 'days' => 31],
                ['name' => 'Emberfall', 'days' => 31],
                ['name' => 'Duskwane', 'days' => 30],
                ['name' => 'Ironmonth', 'days' => 30],
                ['name' => 'Lastlight', 'days' => 31],
            ],
            'weekdays' => ['Forgeday', 'Marketday', 'Scholarday', 'Faithday', 'Councilday', 'Restday'],
            'moons' => [
                ['name' => 'The Warden', 'cycle' => 29, 'offset' => 0, 'colour' => '#cfd2c0'],
                ['name' => 'The Scholar', 'cycle' => 47, 'offset' => 12, 'colour' => '#7a6a9a'],
            ],
            'current_year' => 1147,
            'sort' => 0,
        ]);

        $events = [
            ['year' => 1102, 'month' => 5, 'day' => 9, 'title' => 'The Purchase of Stormhaven', 'description' => 'Niswick buys control of the island north-east of Arcmond rather than take it by force.'],
            ['year' => 1119, 'month' => 7, 'day' => 1, 'title' => 'The Raising of Ironvein', 'description' => 'The Ironvein Fortress is completed at Stormhaven\'s northern point.'],
            ['year' => 1147, 'month' => 6, 'day' => 14, 'title' => 'The Annual Release', 'description' => 'Stormhaven\'s largest festival, when a single prisoner is freed to parades and parties.'],
        ];
        foreach ($events as $event) {
            $calendar->events()->create($event);
        }
    }

    /**
     * A bloodline entry — a family tree — for the ruling Merrow line, with one member linked to the
     * real Lady Merrow NPC so the tree resolves her portrait and entry.
     *
     * @param  array<string, int>  $documents  slug => id
     */
    private function seedBloodline(World $world, User $owner, array $documents): void
    {
        $members = [
            ['id' => 'thane', 'name' => 'Thane Ironfist', 'subtitle' => 'Founder of the line, a general of Arcmond', 'parents' => [], 'partners' => ['brunn']],
            ['id' => 'brunn', 'name' => 'Brunhild Ironfist', 'subtitle' => 'Master smith of Barrenforge', 'parents' => [], 'partners' => ['thane']],
            ['id' => 'gorlak', 'name' => 'Gorlak Ironfist', 'subtitle' => 'Warden of the Ironvein Fortress', 'link' => $documents['gorlak-ironfist'] ?? null,
                'parents' => [['id' => 'thane', 'type' => 'biological'], ['id' => 'brunn', 'type' => 'biological']], 'partners' => []],
            ['id' => 'hilda', 'name' => 'Hilda Ironfist', 'subtitle' => 'Quartermaster, and her brother\'s conscience', 'parents' => [['id' => 'gorlak', 'type' => 'biological']], 'partners' => []],
            ['id' => 'dol', 'name' => 'Dol', 'subtitle' => 'Ward of the house, taken in at Ironvein', 'parents' => [['id' => 'gorlak', 'type' => 'foster']], 'partners' => []],
        ];

        $world->documents()->create([
            'user_id' => $owner->id,
            'title' => 'The Ironfist Line',
            'kind' => 'bloodline',
            'summary' => 'A dwarven line of generals and smiths out of Arcmond — and, in its latest generation, the Warden of Iron Hell.',
            'is_private' => false,
            'tags' => ['dynasty', 'dwarf', 'ironvein'],
            'content' => 'The Ironfists began as generals and smiths of Arcmond, their name a fixture of the shipyards and the forge. Its most notorious son, Gorlak, left the mainland to rule the Ironvein Fortress — a posting the family speaks of with equal parts pride and unease.',
            'data' => ['members' => $members],
        ]);
    }

    /** A reusable block plus location entry and archive templates, seeded into the builder's library. */
    private function seedTemplateLibrary(World $world): void
    {
        // A reusable block set, referenced by the location entry template below.
        $callout = TemplateBlocks::starter('block');
        $callout[0]['settings']['markdown'] = "## Explore Aeria\n\nEvery entry here connects to others — follow the links in the sidebar to chart your own course across the continent.";
        $reusable = WorldBlock::create([
            'world_id' => $world->id,
            'name' => 'Explore-Aeria callout',
            'layout' => ['blocks' => TemplateBlocks::sanitise($callout, true, 'block')],
        ]);

        // A location entry template ending with the reusable callout. Left unassigned (is_default false)
        // so the demo's public pages keep the well-tested built-in layout; it's here to explore and apply.
        $entryBlocks = TemplateBlocks::starter('entry', 'location');
        $entryBlocks[] = ['id' => 'callout', 'type' => 'reusable', 'settings' => ['refId' => $reusable->id], 'css' => ''];
        $world->templates()->create([
            'name' => 'Aerian location',
            'kind' => 'location',
            'target' => 'entry',
            'layout' => [
                'blocks' => TemplateBlocks::sanitise($entryBlocks, true, 'entry'),
                'sidebar' => [],
                'hideSidebar' => false,
                'width' => 'normal',
            ],
            'is_default' => false,
        ]);

        // An archive (section listing) template for the Locations section.
        $world->templates()->create([
            'name' => 'Locations index',
            'kind' => '',
            'target' => 'archive',
            'section' => 'locations',
            'layout' => [
                'blocks' => TemplateBlocks::sanitise(TemplateBlocks::starter('archive'), true, 'archive'),
                'sidebar' => [],
                'hideSidebar' => false,
                'width' => 'normal',
            ],
            'is_default' => false,
        ]);
    }

    /**
     * Richer per-entry metadata: accent, table of contents, comments, related entries, custom-field
     * values (incl. a reference that reads as a real connection), a featured pair, and a scheduled entry.
     *
     * @param  array<string, int>  $documents  slug => id
     */
    private function seedEntryPolish(World $world, User $owner, array $documents): void
    {
        $id = fn (string $slug): ?int => $documents[$slug] ?? null;

        $showcase = $id('ankmier') !== null ? $world->documents()->find($id('ankmier')) : null;
        if ($showcase !== null) {
            $related = array_values(array_filter([$id('lalder'), $id('the-ankmier-council'), $id('the-moldy-eye')]));
            $showcase->update([
                'accent' => '#c98a3a',
                'show_toc' => true,
                'comments_enabled' => true,
                'is_featured' => true,
                'related_ids' => $related,
                'data' => [
                    ...($showcase->data ?? []),
                    'threat' => 'Contested',
                    'exports' => ['Art', 'Trade', 'Cuisine'],
                    'patron' => $id('lalder'),
                ],
            ]);

            // The reference field also reads as a real connection in the web/graph.
            if ($id('lalder') !== null) {
                $world->documentLinks()->create([
                    'from_document_id' => $showcase->id,
                    'to_document_id' => $id('lalder'),
                    'relationship' => 'related_to',
                    'label' => 'Patron',
                    'source' => 'manual',
                ]);
            }
        }

        // A second highlight, so the reader home leads with a pair of featured entries.
        if ($id('ironvein-fortress') !== null) {
            $world->documents()->whereKey($id('ironvein-fortress'))->update(['is_featured' => true]);
        }

        // A scheduled entry: written now, GM-only until it publishes next week.
        $world->documents()->create([
            'user_id' => $owner->id,
            'title' => 'The Warden\'s Gambit',
            'kind' => 'lore',
            'summary' => 'Word of a coming move on the Ironvein Fortress — hidden from players until it publishes.',
            'is_private' => false,
            'publish_at' => now()->addWeek(),
            'content' => 'Someone is buying passage on the prison ship, quietly and completely. In Ankmier they whisper that this year\'s Annual Release has already been decided — and that Warden Ironfist knows exactly who is coming for one of his inmates.',
            'tags' => ['plot', 'ironvein'],
        ]);
    }

    /** A simple stylised section-banner SVG: a coloured wash, a couple of wave lines, and a title. */
    private function sectionSvg(string $label, string $hue): string
    {
        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 300" width="800" height="300">
            <defs>
                <linearGradient id="wash" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="{$hue}"/>
                    <stop offset="100%" stop-color="#0c1a20"/>
                </linearGradient>
            </defs>
            <rect width="800" height="300" fill="url(#wash)"/>
            <g opacity="0.35" stroke="#f3ead2" stroke-width="1.5" fill="none">
                <path d="M0 220 Q200 190 400 220 T800 220"/>
                <path d="M0 250 Q200 220 400 250 T800 250"/>
            </g>
            <text x="40" y="168" font-family="Georgia, serif" font-size="42" fill="#f3ead2" style="paint-order:stroke;stroke:#0b0d10;stroke-width:4px;">{$label}</text>
        </svg>
        SVG;
    }
}
