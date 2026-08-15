<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CampaignCompendiumItem;
use App\Models\Document;
use App\Models\Recap;
use App\Models\RecapEntity;
use App\Models\World;
use App\Support\RecapEntityTarget;
use App\Support\Statblock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The reconciliation engine for a recap's extracted entities: auto-links exact name matches on analysis,
 * searches a world for candidates a GM could link to, and creates a fresh Document/Compendium entry from an
 * entity. The type→target mapping (which system, which kind) lives in {@see RecapEntityTarget}.
 */
class RecapEntityMatcher
{
    /**
     * Replace a recap's extracted entities with a fresh set, auto-linking any whose name exactly matches
     * (case-insensitive) an existing world entry of the mapped kind.
     *
     * @param  list<array{name: string, type: string, description: string}>  $entities
     */
    public function populate(Recap $recap, World $world, array $entities): void
    {
        $recap->entities()->delete();

        foreach ($entities as $entity) {
            $match = $this->exactMatch($world, $entity['type'], $entity['name']);

            $recap->entities()->create([
                'name' => $entity['name'],
                'type' => $entity['type'],
                'description' => $entity['description'],
                'status' => $match === null ? 'unmatched' : 'linked',
                'linked_document_id' => $match['document_id'] ?? null,
                'linked_compendium_item_id' => $match['compendium_item_id'] ?? null,
            ]);
        }
    }

    /**
     * @return array{document_id?: int, compendium_item_id?: int}|null
     */
    public function exactMatch(World $world, string $type, string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        if (RecapEntityTarget::isCompendium($type)) {
            $item = $world->compendiumItems()
                ->where('item_type', RecapEntityTarget::kind($type))
                ->whereRaw('LOWER(name) = LOWER(?)', [$name])
                ->first();

            return $item === null ? null : ['compendium_item_id' => $item->id];
        }

        $document = $world->documents()
            ->where('kind', RecapEntityTarget::kind($type))
            ->whereRaw('LOWER(title) = LOWER(?)', [$name])
            ->first();

        return $document === null ? null : ['document_id' => $document->id];
    }

    /**
     * Existing world entries a GM could link an entity to, filtered by an optional search term.
     *
     * @return list<array{target: string, id: int, name: string, summary: string}>
     */
    public function candidates(World $world, string $type, string $query): array
    {
        $query = trim($query);
        $like = '%'.mb_strtolower($query).'%';

        if (RecapEntityTarget::isCompendium($type)) {
            return $world->compendiumItems()
                ->where('item_type', RecapEntityTarget::kind($type))
                ->when($query !== '', fn (Builder $builder) => $builder->whereRaw('LOWER(name) LIKE ?', [$like]))
                ->orderBy('name')
                ->limit(10)
                ->get()
                ->map(fn (CampaignCompendiumItem $item): array => [
                    'target' => 'compendium',
                    'id' => $item->id,
                    'name' => $item->name,
                    'summary' => (string) $item->summary,
                ])
                ->all();
        }

        return $world->documents()
            ->where('kind', RecapEntityTarget::kind($type))
            ->when($query !== '', fn (Builder $builder) => $builder->whereRaw('LOWER(title) LIKE ?', [$like]))
            ->orderBy('title')
            ->limit(10)
            ->get()
            ->map(fn (Document $document): array => [
                'target' => 'document',
                'id' => $document->id,
                'name' => $document->title,
                'summary' => (string) $document->summary,
            ])
            ->all();
    }

    /**
     * Create a brand-new world entry from an extracted entity and return where it landed.
     *
     * @return array{target: string, id: int}
     */
    public function createEntry(World $world, RecapEntity $entity, int $userId): array
    {
        $description = (string) $entity->description;

        if (RecapEntityTarget::isCompendium($entity->type)) {
            $isMonster = $entity->type === 'monster';

            $item = $world->compendiumItems()->create([
                'user_id' => $userId,
                'item_type' => RecapEntityTarget::kind($entity->type),
                'slug' => Str::slug($entity->name).'-'.Str::lower(Str::random(4)),
                'name' => $entity->name,
                'summary' => Str::limit($description, 240, ''),
                'provider' => 'custom',
                'fields' => $isMonster ? ['block' => Statblock::empty()] : [],
                'document' => $isMonster
                    ? Statblock::toMarkdown(Statblock::empty(), $entity->name)
                    : $this->markdownBody($entity->name, $description),
            ]);

            return ['target' => 'compendium', 'id' => $item->id];
        }

        $kind = RecapEntityTarget::kind($entity->type);

        $document = $world->documents()->create([
            'user_id' => $userId,
            'title' => $entity->name,
            'slug' => Document::uniqueSlug($world->id, $kind, $entity->name),
            'kind' => $kind,
            'content' => $this->markdownBody($entity->name, $description),
            'is_private' => false,
        ]);

        return ['target' => 'document', 'id' => $document->id];
    }

    private function markdownBody(string $name, string $description): string
    {
        $body = "# {$name}\n\n";
        if (trim($description) !== '') {
            $body .= $description."\n";
        }

        return $body;
    }
}
