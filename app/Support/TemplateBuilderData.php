<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Document;
use App\Models\Map;
use App\Models\Media;
use App\Models\World;
use App\Models\WorldBlock;

/**
 * The catalogue payload the visual template builder needs — the block types + starter/presets for a
 * target, the per-kind fields, and the pickers (entries, compendium, media, maps, reusable blocks).
 * Shared by the template builder and the reusable-block builder so both stay in step.
 */
class TemplateBuilderData
{
    /** @return array<string, mixed> */
    public static function for(World $world, string $target = 'entry'): array
    {
        // Real section entries (with their resolved field values) so the archive builder previews against
        // actual data rather than generic samples.
        $sectionSampleItems = [];
        if ($target === 'archive') {
            $visible = $world->documents()->get();
            $sectionSampleItems = collect(Sections::SECTIONS)->mapWithKeys(fn (array $section): array => [
                $section['slug'] => $visible
                    ->filter(fn (Document $d): bool => in_array($d->kind, $section['kinds'], true))
                    ->take(8)->values()
                    ->map(fn (Document $d): array => [
                        'id' => $d->id,
                        'title' => $d->title,
                        'kindLabel' => Sections::kindLabel($d->kind),
                        'summary' => $d->summary,
                        'fields' => collect(Facts::for($d, $visible))
                            ->mapWithKeys(fn (array $fact): array => [$fact['key'] => $fact['value']])->all(),
                    ])->all(),
            ])->all();
        }

        $fieldsByKind = collect(Sections::KINDS)
            ->mapWithKeys(fn (string $kind): array => [$kind => collect(DocFields::for($kind, $world))
                ->map(fn (array $field): array => ['key' => $field['key'], 'label' => $field['label']])
                ->values()
                ->all()])
            ->all();

        return [
            'world' => WorldNav::for($world),
            'kindOptions' => Sections::KINDS,
            'fieldsByKind' => $fieldsByKind,
            'blockTypes' => TemplateBlocks::types($target),
            // The blocks an entry template's right-hand sidebar may hold (widgets + common blocks), and the
            // default sidebar (portrait, quick facts, notes) the builder seeds an empty sidebar with.
            'sidebarBlockTypes' => TemplateBlocks::types('sidebar'),
            'sidebarStarter' => TemplateBlocks::sidebarStarter(),
            'starterBlocks' => TemplateBlocks::starter($target),
            // One-click starting layouts for this target.
            'presets' => TemplateBlocks::presets($target),
            // Sections an archive template can target (slug + label).
            'sectionOptions' => collect(Sections::SECTIONS)->map(fn (array $s): array => ['slug' => $s['slug'], 'label' => $s['label']])->all(),
            // The structured fields a section offers (union across its kinds), for a table's column picker.
            'fieldsBySection' => collect(Sections::SECTIONS)->mapWithKeys(fn (array $s): array => [
                $s['slug'] => collect($s['kinds'])
                    ->flatMap(fn (string $kind): array => DocFields::for($kind, $world))
                    ->map(fn (array $field): array => ['key' => $field['key'], 'label' => $field['label']])
                    ->unique('key')->values()->all(),
            ])->all(),
            // Real entries per section, for an accurate archive preview.
            'sectionSampleItems' => $sectionSampleItems,
            'entriesByKind' => $world->documents()->orderBy('title')->get(['id', 'kind', 'title'])
                ->groupBy('kind')
                ->map(fn ($group) => $group->map(fn (Document $d): array => ['id' => $d->id, 'title' => $d->title])->values())
                ->all(),
            // Compendium entries a Reference block can embed (stat blocks, spells, items…).
            'compendiumItems' => $world->compendiumItems()->orderBy('name')->get(['id', 'name', 'item_type'])
                ->map(fn ($item): array => ['id' => $item->id, 'name' => $item->name, 'type' => Compendium::label($item->item_type)])
                ->all(),
            // The world's media library for the Image block picker.
            'mediaItems' => Media::where('world_id', $world->id)->latest()->limit(200)->get()
                ->map(fn (Media $m): array => ['url' => $m->url, 'name' => $m->alt ?: $m->filename])
                ->all(),
            // The world's maps for the Map block picker.
            'mapOptions' => $world->maps()->orderBy('sort')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Map $map): array => ['id' => $map->id, 'name' => $map->name])
                ->all(),
            // The world's reusable block sets, for the "Reusable" palette group.
            'reusableBlocks' => WorldBlock::where('world_id', $world->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (WorldBlock $b): array => ['id' => $b->id, 'name' => $b->name])
                ->all(),
        ];
    }
}
