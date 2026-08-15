<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Document;
use App\Support\Sections;

/**
 * A world entry reduced to the fields lists and cards need — the one place the document "summary"
 * shape is defined. Callers pick a serialisation ({@see self::toReaderCard()} for public pages,
 * {@see self::toManageRow()} for the GM dashboard) so both surfaces stay in step.
 */
final readonly class DocumentSummaryData
{
    /** @param list<string> $tags */
    public function __construct(
        public int $id,
        public string $title,
        public string $kind,
        public string $slug,
        public ?string $summary,
        public bool $isPrivate,
        public array $tags,
        public ?string $updatedAt,
        public ?string $cardUrl = null,
        public ?string $createdAt = null,
    ) {}

    public static function fromModel(Document $document): self
    {
        return new self(
            id: $document->id,
            title: $document->title,
            kind: $document->kind,
            slug: $document->slug,
            summary: $document->summary,
            isPrivate: (bool) $document->is_private,
            tags: $document->tags ?? [],
            updatedAt: $document->updated_at?->toIso8601String(),
            cardUrl: $document->card?->url,
            createdAt: $document->created_at?->toIso8601String(),
        );
    }

    /**
     * Card shape used across the public reader (overview, section listings, recent).
     *
     * @return array{id: int, title: string, kind: string, type: string, slug: string, kindLabel: string, summary: string|null, is_private: bool, card_url: string|null, updated_at: string|null, created_at: string|null, tags: list<string>}
     */
    public function toReaderCard(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'kind' => $this->kind,
            'type' => Sections::typeSlug($this->kind),
            'slug' => $this->slug,
            'kindLabel' => Sections::kindLabel($this->kind),
            'summary' => $this->summary,
            'is_private' => $this->isPrivate,
            'card_url' => $this->cardUrl,
            'updated_at' => $this->updatedAt,
            'created_at' => $this->createdAt,
            'tags' => $this->tags,
        ];
    }

    /**
     * Row shape used on the GM's world dashboard.
     *
     * @return array{id: int, title: string, kind: string, kindLabel: string, summary: string|null, is_private: bool, tags: list<string>, updated_at: string|null}
     */
    public function toManageRow(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'kind' => $this->kind,
            'kindLabel' => Sections::kindLabel($this->kind),
            'summary' => $this->summary,
            'is_private' => $this->isPrivate,
            'tags' => $this->tags,
            'updated_at' => $this->updatedAt,
        ];
    }
}
