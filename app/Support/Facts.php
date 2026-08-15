<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Document;
use Illuminate\Support\Collection;

/**
 * "Quick facts" for a field-kind entry (location, npc, faction, spell): each visible structured
 * field that carries a value, shown as a label/value pair in the reader's right rail. Mirrors the
 * editor's live-preview facts panel (see FieldEditor.vue).
 */
class Facts
{
    /**
     * @param  Collection<int, Document>  $visible  entries this viewer may see, for reference resolution
     * @param  list<string>  $only  an ordered subset of field keys to show (from the entry's template);
     *                              empty means show every field in the kind's default order
     * @return list<array{key: string, label: string, value: string, link: array{type: string, slug: string}|null, items: list<array{value: string, link: array{type: string, slug: string}|null}>}>
     */
    public static function for(Document $document, Collection $visible, array $only = []): array
    {
        $byId = $visible->keyBy('id');

        $fields = collect(DocFields::for($document->kind, $document->world));

        // A template may restrict quick facts to a chosen subset, in a chosen order.
        if ($only !== []) {
            $rank = array_flip($only);
            $fields = $fields
                ->filter(fn (array $field): bool => isset($rank[$field['key']]))
                ->sortBy(fn (array $field): int => $rank[$field['key']])
                ->values();
        }

        return $fields
            ->map(function (array $field) use ($document, $byId): ?array {
                $type = $field['type'] ?? 'text';
                $raw = data_get($document->data, $field['key']);

                // A "multiple" field holds several values; a single field is treated as one.
                $rawValues = ($field['multiple'] ?? false) ? (is_array($raw) ? $raw : []) : [$raw];

                $items = collect($rawValues)
                    ->map(fn (mixed $value): ?array => self::resolveOne($type, $value, $byId))
                    ->filter()
                    ->values()
                    ->all();

                if ($items === []) {
                    return null;
                }

                return [
                    'key' => $field['key'],
                    'label' => $field['label'],
                    'items' => $items,
                    // Joined fallback + the first link, for the single-value rendering path.
                    'value' => collect($items)->pluck('value')->implode(', '),
                    'link' => $items[0]['link'] ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Document>  $byId
     * @return array{value: string, link: array{type: string, slug: string}|null}|null
     */
    private static function resolveOne(string $type, mixed $raw, Collection $byId): ?array
    {
        if ($type === 'reference') {
            $target = $byId->get($raw);

            if (! $target instanceof Document) {
                return null;
            }

            return ['value' => $target->title, 'link' => ['type' => Sections::typeSlug($target->kind), 'slug' => $target->slug]];
        }

        $value = self::stringValue($type, $raw);

        return $value === '' ? null : ['value' => $value, 'link' => null];
    }

    private static function stringValue(string $type, mixed $raw): string
    {
        if ($type === 'boolean') {
            return $raw === true || $raw === 'true' || $raw === 1 || $raw === '1' ? 'Yes' : '';
        }

        // Whitespace trim only — ASCII whitespace never forms part of a multibyte sequence, so
        // trim() is byte-safe here (mb_trim is unavailable below PHP 8.4).
        return is_scalar($raw) ? trim((string) $raw) : '';
    }
}
