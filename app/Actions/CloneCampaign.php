<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Campaign;
use App\Models\Document;
use App\Models\Media;
use App\Models\Room;
use App\Models\User;
use App\Models\World;
use App\Models\WorldBlock;
use App\Support\DocFields;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Deep-copy a whole world into a new world owned by a user. Used to spin up a private, editable copy
 * of the seeded sandbox (or any world) so the recipient gets the full GM toolset without touching the
 * original. Copies the written world (documents), the compendium, maps + pins, document links, and any
 * battle rooms with their scenes/tokens/templates/drawings. Sessions are copied into the new world's
 * Main Campaign. Media files are physically duplicated so deleting one world's image never affects the
 * source. Player-specific data (characters, members, invites, chat) is not copied.
 */
class CloneCampaign
{
    /** old media id => new media id, so a shared image is only copied once per clone. */
    private array $mediaMap = [];

    /** The world being written into, so cloned media rows are filed under it. */
    private ?World $targetWorld = null;

    /**
     * @param  array<string, mixed>  $overrides  World attributes to override on the copy (e.g. name, visibility).
     */
    public function handle(World $source, User $owner, array $overrides = []): World
    {
        $this->mediaMap = [];

        return DB::transaction(function () use ($source, $owner, $overrides): World {
            $world = $owner->worlds()->create([
                'name' => $overrides['name'] ?? $source->name,
                'description' => $overrides['description'] ?? $source->description,
                'setting' => $overrides['setting'] ?? $source->setting,
                // A clone is always a private, editable world — never another sandbox.
                'visibility' => $overrides['visibility'] ?? 'private',
                'is_sandbox' => false,
            ]);
            $this->targetWorld = $world;

            // The World::created hook auto-creates a "Main Campaign"; use it as the play-data target.
            $targetCampaign = $world->campaigns()->first();

            $cover = $this->cloneMedia($source->cover_media_id, $owner->id);
            if ($cover !== null) {
                $world->update(['cover_media_id' => $cover]);
            }

            $compendiumMap = $this->cloneCompendium($source, $world, $owner->id);
            $documentMap = $this->cloneDocuments($source, $world, $owner->id, $compendiumMap);
            $this->cloneDocumentLinks($source, $world, $documentMap);
            $this->cloneMaps($source, $world, $owner->id, $documentMap);

            // World-scoped authoring: custom fields, roll tables, the calendar, reusable blocks and
            // reader templates, plus the world's own reader chrome (settings, field order, banner).
            $this->cloneWorldChrome($source, $world, $owner->id);
            $this->cloneWorldFields($source, $world);
            $this->cloneRollTables($source, $world);
            $this->cloneCalendars($source, $world);
            $blockMap = $this->cloneReusableBlocks($source, $world);
            $templateMap = $this->cloneTemplates($source, $world, $blockMap);

            // A second pass now that every id is known: remap the document references that point at
            // other documents (related entries, chosen template, and reference-type field values).
            $this->remapClonedDocuments($source, $documentMap, $templateMap);

            // Clone play data from the source's campaigns into the new world's Main Campaign.
            foreach ($source->campaigns as $sourceCampaign) {
                $this->cloneRooms($sourceCampaign, $targetCampaign, $owner->id, $compendiumMap, $documentMap);
                $this->cloneSessions($sourceCampaign, $targetCampaign);
            }

            return $world;
        });
    }

    /** @return array<int, int> old compendium item id => new id */
    private function cloneCompendium(World $sourceWorld, World $targetWorld, int $ownerId): array
    {
        $map = [];
        foreach ($sourceWorld->compendiumItems as $item) {
            $copy = $targetWorld->compendiumItems()->create([
                'user_id' => $ownerId,
                'item_type' => $item->item_type,
                'slug' => $item->slug,
                'name' => $item->name,
                'summary' => $item->summary,
                'document' => $item->document,
                'fields' => $item->fields,
                'data' => $item->data,
                'provider' => $item->provider,
                'origin' => $item->origin,
                'is_private' => $item->is_private,
                'tags' => $item->tags,
                'is_active' => $item->is_active,
                'image_media_id' => $this->cloneMedia($item->image_media_id, $ownerId),
            ]);
            $map[$item->id] = $copy->id;
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $compendiumMap
     * @return array<int, int> old document id => new id
     */
    private function cloneDocuments(World $sourceWorld, World $targetWorld, int $ownerId, array $compendiumMap): array
    {
        $map = [];
        foreach ($sourceWorld->documents as $document) {
            $copy = $targetWorld->documents()->create([
                'user_id' => $ownerId,
                'title' => $document->title,
                'slug' => $document->slug,
                'kind' => $document->kind,
                'summary' => $document->summary,
                // Rewrite {{monster=id}} / {{spell=id}} embeds to the freshly-cloned compendium ids.
                'content' => $this->remapEmbeds($document->content, $compendiumMap),
                // Reference-type field values inside `data` still point at source document ids; they're
                // remapped in remapClonedDocuments() once the full id map exists.
                'data' => $document->data,
                'is_private' => $document->is_private,
                'tags' => $document->tags,
                // Per-entry presentation and lifecycle metadata.
                'accent' => $document->accent,
                'publish_at' => $document->publish_at,
                'comments_enabled' => $document->comments_enabled,
                'show_toc' => $document->show_toc,
                'slug_aliases' => $document->slug_aliases,
                'is_featured' => $document->is_featured,
                'hide_from_search' => $document->hide_from_search,
                'cover_mode' => $document->cover_mode,
                'card_media_id' => $this->cloneMedia($document->card_media_id, $ownerId),
                'banner_media_id' => $this->cloneMedia($document->banner_media_id, $ownerId),
            ]);
            $map[$document->id] = $copy->id;
        }

        return $map;
    }

    /** @param  array<int, int>  $documentMap */
    private function cloneDocumentLinks(World $sourceWorld, World $targetWorld, array $documentMap): void
    {
        foreach ($sourceWorld->documents as $document) {
            foreach ($document->outgoingLinks as $link) {
                $from = $documentMap[$link->from_document_id] ?? null;
                $to = $documentMap[$link->to_document_id] ?? null;
                if ($from === null || $to === null) {
                    continue;
                }
                $targetWorld->documentLinks()->create([
                    'from_document_id' => $from,
                    'to_document_id' => $to,
                    'label' => $link->label,
                    'relationship' => $link->relationship,
                    'source' => $link->source,
                ]);
            }
        }
    }

    /** @param  array<int, int>  $documentMap */
    private function cloneMaps(World $sourceWorld, World $targetWorld, int $ownerId, array $documentMap): void
    {
        foreach ($sourceWorld->maps()->with('pins')->get() as $map) {
            $copy = $targetWorld->maps()->create([
                'image_media_id' => $this->cloneMedia($map->image_media_id, $ownerId),
                'document_id' => $documentMap[$map->document_id] ?? null,
                'name' => $map->name,
                'slug' => $map->slug,
                'is_private' => $map->is_private,
                'sort' => $map->sort,
                'grid_visible' => $map->grid_visible,
                'grid_size' => $map->grid_size,
                'unit_size' => $map->unit_size,
                'unit' => $map->unit,
                'fog_enabled' => $map->fog_enabled,
                'fog' => $map->fog,
            ]);
            foreach ($map->pins as $pin) {
                $copy->pins()->create([
                    'document_id' => $documentMap[$pin->document_id] ?? null,
                    'behavior' => $pin->behavior,
                    'x' => $pin->x,
                    'y' => $pin->y,
                    'label' => $pin->label,
                    'note' => $pin->note,
                ]);
            }
        }
    }

    /**
     * @param  array<int, int>  $compendiumMap
     * @param  array<int, int>  $documentMap
     */
    private function cloneRooms(Campaign $sourceCampaign, Campaign $targetCampaign, int $ownerId, array $compendiumMap, array $documentMap): void
    {
        $rooms = $sourceCampaign->rooms()->with(['scenes', 'tokens', 'templates', 'drawings'])->get();
        foreach ($rooms as $room) {
            $copy = $targetCampaign->rooms()->create([
                'created_by' => $ownerId,
                'image_media_id' => $this->cloneMedia($room->image_media_id, $ownerId),
                'name' => $room->name,
                'grid_visible' => $room->grid_visible,
                'grid_size' => $room->grid_size,
                'unit_size' => $room->unit_size,
                'unit' => $room->unit,
                'fog_enabled' => $room->fog_enabled,
                'fog' => $room->fog,
                'round' => 1,
                // player_monster_ids point at compendium items — remap to the clone's copies.
                'player_monster_ids' => collect($room->player_monster_ids ?? [])
                    ->map(fn ($id) => $compendiumMap[(int) $id] ?? null)->filter()->values()->all(),
                'players_see_tracker' => $room->players_see_tracker,
                'voice_enabled' => $room->voice_enabled,
            ]);

            // A new room auto-creates a "Scene 1"; drop it so the source's scenes clone cleanly.
            $copy->scenes()->delete();

            $sceneMap = [];
            foreach ($room->scenes as $scene) {
                $newScene = $copy->scenes()->create([
                    'name' => $scene->name,
                    'image_media_id' => $this->cloneMedia($scene->image_media_id, $ownerId),
                    'grid_visible' => $scene->grid_visible,
                    'grid_size' => $scene->grid_size,
                    'unit_size' => $scene->unit_size,
                    'unit' => $scene->unit,
                    'fog_enabled' => $scene->fog_enabled,
                    'fog' => $scene->fog,
                    'sort' => $scene->sort,
                ]);
                $sceneMap[$scene->id] = $newScene->id;
            }

            $this->cloneRoomTokens($room, $copy, $ownerId, $sceneMap, $compendiumMap, $documentMap);
            $this->cloneRoomOverlays($room, $copy, $ownerId, $sceneMap);

            $copy->forceFill([
                'active_scene_id' => $sceneMap[$room->active_scene_id] ?? $copy->scenes()->min('id'),
            ])->saveQuietly();
        }
    }

    /** Clone sessions from a source campaign into the target campaign (skips characters, members, invites). */
    private function cloneSessions(Campaign $sourceCampaign, Campaign $targetCampaign): void
    {
        foreach ($sourceCampaign->sessions as $session) {
            $targetCampaign->sessions()->create([
                'title' => $session->title,
                'slug' => $session->slug,
                'summary' => $session->summary,
                'body' => $session->body,
                'held_on' => $session->held_on,
                'sort' => $session->sort,
                'is_private' => $session->is_private,
            ]);
        }
    }

    /**
     * @param  array<int, int>  $sceneMap
     * @param  array<int, int>  $compendiumMap
     * @param  array<int, int>  $documentMap
     */
    private function cloneRoomTokens(Room $room, Room $copy, int $ownerId, array $sceneMap, array $compendiumMap, array $documentMap): void
    {
        foreach ($room->tokens as $token) {
            $copy->tokens()->create([
                'scene_id' => $token->scene_id ? ($sceneMap[$token->scene_id] ?? null) : null,
                // Cloned tokens are GM-controlled; player characters are not carried over.
                'user_id' => null,
                'character_id' => null,
                'compendium_item_id' => $token->compendium_item_id ? ($compendiumMap[$token->compendium_item_id] ?? null) : null,
                'document_id' => $token->document_id ? ($documentMap[$token->document_id] ?? null) : null,
                'image_media_id' => $this->cloneMedia($token->image_media_id, $ownerId),
                'ddb_character_id' => $token->ddb_character_id,
                'kind' => $token->kind,
                'layer' => $token->layer,
                'x' => $token->x,
                'y' => $token->y,
                'size' => $token->size,
                'label' => $token->label,
                'color' => $token->color,
                'hp' => $token->hp,
                'max_hp' => $token->max_hp,
                'ac' => $token->ac,
                'notes' => $token->notes,
                'conditions' => $token->conditions,
                'initiative' => $token->initiative,
                'elevation' => $token->elevation,
                'in_tracker' => $token->in_tracker,
            ]);
        }
    }

    /** @param  array<int, int>  $sceneMap */
    private function cloneRoomOverlays(Room $room, Room $copy, int $ownerId, array $sceneMap): void
    {
        foreach ($room->templates as $template) {
            $copy->templates()->create([
                'scene_id' => $template->scene_id ? ($sceneMap[$template->scene_id] ?? null) : null,
                'created_by' => $ownerId,
                'kind' => $template->kind,
                'layer' => $template->layer,
                'x' => $template->x,
                'y' => $template->y,
                'length' => $template->length,
                'angle' => $template->angle,
                'color' => $template->color,
            ]);
        }
        foreach ($room->drawings as $drawing) {
            $copy->drawings()->create([
                'scene_id' => $drawing->scene_id ? ($sceneMap[$drawing->scene_id] ?? null) : null,
                'created_by' => $ownerId,
                'kind' => $drawing->kind,
                'layer' => $drawing->layer,
                'points' => $drawing->points,
                'color' => $drawing->color,
                'fill' => $drawing->fill,
            ]);
        }
    }

    /** Copy the world's reader chrome: settings (with section-image media remapped), field order and branding. */
    private function cloneWorldChrome(World $source, World $target, int $ownerId): void
    {
        $settings = $source->settings ?? [];

        // Section-door images are stored as media URLs; clone each underlying media file so the copy owns
        // its own, and rewrite the URLs to match.
        if (! empty($settings['section_images']) && is_array($settings['section_images'])) {
            $mediaByUrl = Media::where('world_id', $source->id)->get()->keyBy(fn (Media $media): string => $media->url);
            $remapped = [];
            foreach ($settings['section_images'] as $slug => $url) {
                $media = $mediaByUrl->get($url);
                $newId = $media !== null ? $this->cloneMedia($media->id, $ownerId) : null;
                $newMedia = $newId !== null ? Media::find($newId) : null;
                if ($newMedia !== null) {
                    $remapped[$slug] = $newMedia->url;
                }
            }
            $settings['section_images'] = $remapped;
        }

        $target->update([
            'settings' => $settings,
            'field_order' => $source->field_order,
            'banner_media_id' => $this->cloneMedia($source->banner_media_id, $ownerId),
            'logo_media_id' => $this->cloneMedia($source->logo_media_id, $ownerId),
            'favicon_media_id' => $this->cloneMedia($source->favicon_media_id, $ownerId),
            'og_media_id' => $this->cloneMedia($source->og_media_id, $ownerId),
        ]);
    }

    /** Copy the world's custom quick-facts fields. */
    private function cloneWorldFields(World $source, World $target): void
    {
        foreach ($source->customFields as $field) {
            $target->customFields()->create($field->only([
                'key', 'label', 'type', 'multiple', 'options', 'ref_kinds', 'kinds',
                'link_label', 'inverse_label', 'required', 'visible', 'help', 'placeholder', 'sort_order',
            ]));
        }
    }

    /** Copy the world's roll tables. */
    private function cloneRollTables(World $source, World $target): void
    {
        foreach ($source->rollTables as $table) {
            $target->rollTables()->create($table->only(['name', 'description', 'die', 'rows', 'is_private']));
        }
    }

    /** Copy the world's calendars and their events. */
    private function cloneCalendars(World $source, World $target): void
    {
        foreach ($source->calendars()->with('events')->get() as $calendar) {
            $copy = $target->calendars()->create($calendar->only([
                'name', 'slug', 'months', 'weekdays', 'leap_rules', 'moons', 'current_year', 'sort',
            ]));
            foreach ($calendar->events as $event) {
                $copy->events()->create($event->only(['year', 'month', 'day', 'title', 'description']));
            }
        }
    }

    /**
     * Copy the world's reusable block sets.
     *
     * @return array<int, int> old block id => new id
     */
    private function cloneReusableBlocks(World $source, World $target): array
    {
        $map = [];
        foreach (WorldBlock::where('world_id', $source->id)->get() as $block) {
            $copy = WorldBlock::create([
                'world_id' => $target->id,
                'name' => $block->name,
                'layout' => $block->layout,
            ]);
            $map[$block->id] = $copy->id;
        }

        return $map;
    }

    /**
     * Copy the world's reader templates, remapping any reusable-block references to the cloned blocks.
     *
     * @param  array<int, int>  $blockMap
     * @return array<int, int> old template id => new id
     */
    private function cloneTemplates(World $source, World $target, array $blockMap): array
    {
        $map = [];
        foreach ($source->templates as $template) {
            $layout = $template->layout ?? [];
            if (isset($layout['blocks']) && is_array($layout['blocks'])) {
                $layout['blocks'] = $this->remapReusableRefs($layout['blocks'], $blockMap);
            }
            $copy = $target->templates()->create([
                'name' => $template->name,
                'kind' => $template->kind,
                'target' => $template->target,
                'section' => $template->section,
                'layout' => $layout,
                'is_default' => $template->is_default,
            ]);
            $map[$template->id] = $copy->id;
        }

        return $map;
    }

    /**
     * Point every "reusable" block's refId at the cloned block, recursing into columns and repeaters.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<int, int>  $blockMap
     * @return list<array<string, mixed>>
     */
    private function remapReusableRefs(array $blocks, array $blockMap): array
    {
        return array_map(function (array $block) use ($blockMap): array {
            if (($block['type'] ?? null) === 'reusable') {
                $old = $block['settings']['refId'] ?? null;
                $block['settings']['refId'] = $old !== null ? ($blockMap[(int) $old] ?? null) : null;
            }
            if (isset($block['settings']['cols']) && is_array($block['settings']['cols'])) {
                $block['settings']['cols'] = array_map(
                    fn (mixed $column): array => is_array($column) ? $this->remapReusableRefs($column, $blockMap) : [],
                    $block['settings']['cols'],
                );
            }
            if (isset($block['settings']['blocks']) && is_array($block['settings']['blocks'])) {
                $block['settings']['blocks'] = $this->remapReusableRefs($block['settings']['blocks'], $blockMap);
            }

            return $block;
        }, $blocks);
    }

    /**
     * Second pass over the cloned documents: remap the ids that point at other documents — related
     * entries, the chosen template, reference-type custom-field values, and bloodline member links.
     *
     * @param  array<int, int>  $documentMap
     * @param  array<int, int>  $templateMap
     */
    private function remapClonedDocuments(World $source, array $documentMap, array $templateMap): void
    {
        foreach ($source->documents as $document) {
            $newId = $documentMap[$document->id] ?? null;
            $copy = $newId !== null ? Document::find($newId) : null;
            if ($copy === null) {
                continue;
            }

            $related = collect($document->related_ids ?? [])
                ->map(fn (mixed $id): ?int => $documentMap[(int) $id] ?? null)
                ->filter()
                ->values()
                ->all();

            $data = $document->data ?? [];

            // Reference-type custom fields hold document ids (single, or a list when multiple).
            foreach (DocFields::for($document->kind, $source) as $field) {
                if (($field['type'] ?? null) !== 'reference' || ! array_key_exists($field['key'], $data)) {
                    continue;
                }
                $data[$field['key']] = ($field['multiple'] ?? false)
                    ? collect((array) $data[$field['key']])->map(fn (mixed $id): ?int => $documentMap[(int) $id] ?? null)->filter()->values()->all()
                    : ($documentMap[(int) $data[$field['key']]] ?? null);
            }

            // A bloodline's members link to real entries by id inside data.members[].link.
            if ($document->kind === 'bloodline' && isset($data['members']) && is_array($data['members'])) {
                $data['members'] = array_map(function (mixed $member) use ($documentMap): mixed {
                    if (is_array($member) && isset($member['link']) && is_numeric($member['link'])) {
                        $member['link'] = $documentMap[(int) $member['link']] ?? null;
                    }

                    return $member;
                }, $data['members']);
            }

            // Model update so the array casts (data, related_ids) are applied on write.
            $copy->update([
                'data' => $data,
                'related_ids' => $related !== [] ? $related : null,
                'template_id' => $document->template_id !== null ? ($templateMap[$document->template_id] ?? null) : null,
            ]);
        }
    }

    /**
     * Rewrite `{{type=id}}` compendium embeds in document markdown to the cloned item ids.
     *
     * @param  array<int, int>  $compendiumMap
     */
    private function remapEmbeds(?string $content, array $compendiumMap): ?string
    {
        if ($content === null) {
            return null;
        }

        return preg_replace_callback('/\{\{([a-z]+)=(\d+)((?:,[^}]*)?)\}\}/i', function (array $matches) use ($compendiumMap): string {
            $newId = $compendiumMap[(int) $matches[2]] ?? null;

            return $newId === null ? $matches[0] : '{{'.$matches[1].'='.$newId.$matches[3].'}}';
        }, $content) ?? $content;
    }

    /** Duplicate a media file + row so the clone owns its own copy (deleting it never touches the source). */
    private function cloneMedia(?int $mediaId, int $ownerId): ?int
    {
        if ($mediaId === null) {
            return null;
        }
        if (isset($this->mediaMap[$mediaId])) {
            return $this->mediaMap[$mediaId];
        }

        $media = Media::find($mediaId);
        if ($media === null) {
            return null;
        }

        $extension = pathinfo($media->path, PATHINFO_EXTENSION);
        $newPath = 'media/'.Str::uuid()->toString().($extension !== '' ? ".{$extension}" : '');
        $disk = Storage::disk($media->disk);
        if ($disk->exists($media->path)) {
            $disk->copy($media->path, $newPath);
        } else {
            // The source file is missing; share the path rather than fail the whole clone.
            $newPath = $media->path;
        }

        $copy = Media::create([
            'user_id' => $ownerId,
            'world_id' => $this->targetWorld?->id,
            'disk' => $media->disk,
            'path' => $newPath,
            'filename' => $media->filename,
            'mime' => $media->mime,
            'size' => $media->size,
            'alt' => $media->alt,
        ]);

        return $this->mediaMap[$mediaId] = $copy->id;
    }
}
