<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\RecapEntity;

/**
 * Shapes a {@see RecapEntity} for the frontend: its editable fields, reconciliation status, and a link to
 * the real world entry it's tied to (with a URL to open it) when one exists.
 */
final class RecapEntityPresenter
{
    /**
     * @return array{
     *     id: int, name: string, type: string, description: string|null, status: string,
     *     link: array{target: string, id: int, name: string, url: string}|null,
     * }
     */
    public static function present(RecapEntity $entity): array
    {
        return [
            'id' => $entity->id,
            'name' => $entity->name,
            'type' => $entity->type,
            'description' => $entity->description,
            'status' => $entity->status,
            'link' => self::link($entity),
        ];
    }

    /**
     * @return array{target: string, id: int, name: string, url: string}|null
     */
    private static function link(RecapEntity $entity): ?array
    {
        if ($entity->linked_document_id !== null) {
            $document = $entity->linkedDocument;

            return $document === null ? null : [
                'target' => 'document',
                'id' => $document->id,
                'name' => $document->title,
                'url' => route('documents.edit', $document->id),
            ];
        }

        if ($entity->linked_compendium_item_id !== null) {
            $item = $entity->linkedCompendiumItem;

            return $item === null ? null : [
                'target' => 'compendium',
                'id' => $item->id,
                'name' => $item->name,
                'url' => route('compendium.edit', $item->id),
            ];
        }

        return null;
    }
}
