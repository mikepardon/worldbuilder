<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Wiki-style [[links]] inside document content. A link may be written as [[Title]] or [[Title|shown
 * text]]; only the title before the pipe is used to resolve the target. Resolved links are mirrored
 * into the document_links table (source = "wikilink") so they feed the connection graph.
 */
class WikiLinks
{
    private const PATTERN = '/\[\[([^\[\]]+)\]\]/';

    /**
     * Distinct target titles referenced by [[...]] in the content.
     *
     * @return list<string>
     */
    public static function titles(?string $content): array
    {
        if (blank($content) || preg_match_all(self::PATTERN, (string) $content, $matches) === 0) {
            return [];
        }

        return collect($matches[1])
            ->map(fn (string $raw) => trim(Str::before($raw, '|')))
            ->filter()
            ->unique(fn (string $title) => Str::lower($title))
            ->values()
            ->all();
    }

    /**
     * Re-derive a document's wiki-link connections from its current content, replacing any it had
     * before. Titles are matched case-insensitively against other entries in the same world.
     */
    public static function sync(Document $document): void
    {
        $titles = self::titles($document->content);

        $targets = collect();
        if ($titles !== []) {
            $lowered = array_map(fn (string $title) => Str::lower($title), $titles);
            $targets = Document::query()
                ->where('world_id', $document->world_id)
                ->where('id', '!=', $document->id)
                ->get(['id', 'title'])
                ->filter(fn (Document $target) => in_array(Str::lower($target->title), $lowered, true))
                ->values();
        }

        DB::transaction(function () use ($document, $targets) {
            $document->outgoingLinks()->where('source', 'wikilink')->delete();

            foreach ($targets as $target) {
                $document->outgoingLinks()->create([
                    'world_id' => $document->world_id,
                    'to_document_id' => $target->id,
                    'label' => null,
                    'relationship' => 'mentions',
                    'source' => 'wikilink',
                ]);
            }
        });
    }
}
