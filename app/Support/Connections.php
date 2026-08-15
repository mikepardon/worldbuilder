<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\World;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds the node/edge graph of a world's entries and their connections, for the "Web" view and the
 * per-article mini-graph. Duplicate edges between the same ordered pair (e.g. a hand-added link and a
 * wiki-link) are merged into one, preferring a meaningful relationship over a bare "mentions". Each
 * edge carries both a forward and an inverse phrase so it reads naturally from either endpoint.
 */
class Connections
{
    /**
     * @return array{
     *     nodes: list<array{id: int, title: string, kind: string, type: string, slug: string, kindLabel: string, is_private: bool, degree: int, summary: string|null, image: string|null}>,
     *     edges: list<array{from: int, to: int, relationship: string, label: string, inverseLabel: string}>,
     * }
     *
     * When a $viewer is given who cannot see private lore, the graph is scoped to the entries that
     * viewer may see — private and not-yet-published entries (and any edge that touches one) are
     * dropped — so the public reader web never leaks GM-only entries.
     */
    public static function graph(World $world, ?Viewer $viewer = null): array
    {
        $query = $world->documents()->with('card')->orderBy('title');

        if ($viewer !== null && ! $viewer->seesPrivate()) {
            $query->where('is_private', false)
                ->where(fn (Builder $builder) => $builder->whereNull('publish_at')->orWhere('publish_at', '<=', now()));
        }

        $documents = $query->get();
        $documentIds = $documents->pluck('id')->all();
        $visibleIds = array_flip($documentIds);

        $links = DocumentLink::query()
            ->where('world_id', $world->id)
            ->get(['from_document_id', 'to_document_id', 'label', 'relationship', 'source']);

        $edges = [];
        foreach ($links as $link) {
            // An edge only survives if both of its entries are visible to this viewer.
            if (! isset($visibleIds[$link->from_document_id]) || ! isset($visibleIds[$link->to_document_id])) {
                continue;
            }

            $key = "{$link->from_document_id}-{$link->to_document_id}";
            $relationship = Relationships::normalise($link->relationship);

            if (! isset($edges[$key])) {
                $edges[$key] = [
                    'from' => $link->from_document_id,
                    'to' => $link->to_document_id,
                    'relationship' => $relationship,
                    'custom' => $link->label,
                    'source' => $link->source,
                    'label' => null,
                    'inverseLabel' => null,
                ];

                continue;
            }

            // A specific hand-added relationship should win over a wiki "mentions" between the same pair.
            $existingIsWeak = $edges[$key]['source'] === 'wikilink' || $edges[$key]['relationship'] === 'mentions';
            $incomingIsStrong = $link->source !== 'wikilink' && $relationship !== 'mentions';
            if ($existingIsWeak && $incomingIsStrong) {
                $edges[$key]['relationship'] = $relationship;
                $edges[$key]['custom'] = $link->label;
                $edges[$key]['source'] = $link->source;
            } elseif (blank($edges[$key]['custom']) && filled($link->label)) {
                $edges[$key]['custom'] = $link->label;
            }
        }

        self::addReferenceFieldEdges($edges, $world, $documents, $documentIds);

        $degree = [];
        foreach ($edges as $edge) {
            $degree[$edge['from']] = ($degree[$edge['from']] ?? 0) + 1;
            $degree[$edge['to']] = ($degree[$edge['to']] ?? 0) + 1;
        }

        $nodes = $documents->map(fn (Document $document) => [
            'id' => $document->id,
            'title' => $document->title,
            'kind' => $document->kind,
            'type' => Sections::typeSlug($document->kind),
            'slug' => $document->slug,
            'kindLabel' => Sections::kindLabel($document->kind),
            'is_private' => $document->is_private,
            'degree' => $degree[$document->id] ?? 0,
            'summary' => filled($document->summary) ? Str::limit((string) $document->summary, 90) : null,
            'image' => $document->card?->url,
        ])->values()->all();

        // A reference field carries its own forward/inverse phrases; everything else reads from the
        // relationship catalogue (with a hand-added custom label winning).
        $edges = collect($edges)->map(fn (array $edge) => [
            'from' => $edge['from'],
            'to' => $edge['to'],
            'relationship' => $edge['relationship'],
            'label' => $edge['label'] ?? Relationships::label($edge['relationship'], $edge['custom']),
            'inverseLabel' => $edge['inverseLabel'] ?? Relationships::inverse($edge['relationship'], $edge['custom']),
        ])->values()->all();

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * The connections of a single document, drawn from a computed graph: each neighbour as a card plus
     * the relationship phrase read from this document's side (forward for its outgoing edges, inverse for
     * its incoming ones). Used by the "Connections" template block.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return list<array{id: int, title: string, type: string, slug: string, kindLabel: string, image: string|null, label: string|null}>
     */
    public static function neighbours(array $graph, int $documentId): array
    {
        $nodes = collect($graph['nodes'])->keyBy('id');
        $connections = [];

        foreach ($graph['edges'] as $edge) {
            if ($edge['from'] === $documentId && isset($nodes[$edge['to']])) {
                $connections[] = self::neighbourCard($nodes[$edge['to']], $edge['label']);
            } elseif ($edge['to'] === $documentId && isset($nodes[$edge['from']])) {
                $connections[] = self::neighbourCard($nodes[$edge['from']], $edge['inverseLabel']);
            }
        }

        return $connections;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{id: int, title: string, type: string, slug: string, kindLabel: string, image: string|null, label: string|null}
     */
    private static function neighbourCard(array $node, ?string $label): array
    {
        return [
            'id' => $node['id'],
            'title' => $node['title'],
            'type' => $node['type'],
            'slug' => $node['slug'],
            'kindLabel' => $node['kindLabel'],
            'image' => $node['image'] ?? null,
            'label' => $label,
        ];
    }

    /**
     * Fold each document's reference-field values into the edge map. A reference field is itself a
     * connection: "Owner" on a location points to a person, reading "owned by" from the location and
     * "owner of" from the person. Field edges are explicit, so they upgrade a weak wiki "mentions".
     *
     * @param  array<string, array{from: int, to: int, relationship: string, custom: string|null, source: string, label: string|null, inverseLabel: string|null}>  $edges
     * @param  Collection<int, Document>  $documents
     * @param  list<int>  $documentIds
     */
    private static function addReferenceFieldEdges(array &$edges, World $world, $documents, array $documentIds): void
    {
        // The reference fields per kind, resolved once (defaults merged with the world's own).
        $referenceFields = [];

        foreach ($documents as $document) {
            $fields = $referenceFields[$document->kind] ??= collect(DocFields::for($document->kind, $world))
                ->filter(fn (array $field): bool => ($field['type'] ?? 'text') === 'reference')
                ->all();

            foreach ($fields as $field) {
                $raw = data_get($document->data, $field['key']);
                $targets = is_array($raw) ? $raw : [$raw];

                foreach ($targets as $target) {
                    if (! is_int($target) && ! (is_string($target) && ctype_digit($target))) {
                        continue;
                    }

                    $targetId = (int) $target;
                    if ($targetId === $document->id || ! in_array($targetId, $documentIds, true)) {
                        continue;
                    }

                    $forward = filled($field['link_label'] ?? null) ? $field['link_label'] : $field['label'];
                    $inverse = filled($field['inverse_label'] ?? null) ? $field['inverse_label'] : $field['label'];
                    $key = "{$document->id}-{$targetId}";

                    // A brand-new pair, or an upgrade of a weak wiki "mentions" between the same pair.
                    $existing = $edges[$key] ?? null;
                    if ($existing === null || $existing['source'] === 'wikilink' || $existing['relationship'] === 'mentions') {
                        $edges[$key] = [
                            'from' => $document->id,
                            'to' => $targetId,
                            'relationship' => 'reference',
                            'custom' => null,
                            'source' => 'field',
                            'label' => $forward,
                            'inverseLabel' => $inverse,
                        ];
                    }
                }
            }
        }
    }
}
