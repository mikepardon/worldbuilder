<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\World;
use App\Support\WikiLinks;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Re-derive every document's [[wiki-link]] connections into the document_links table. Documents created
 * outside the editor (seeders, generators, imports) may have prose links that were never synced, leaving
 * the connections web empty; this backfills them. Hand-added relationships (source != "wikilink") are
 * untouched — {@see WikiLinks::sync()} only replaces the wiki-link edges.
 */
class ResyncDocumentLinks extends Command
{
    protected $signature = 'documents:resync-links {world? : A world id or slug to scope to; omit for every world}';

    protected $description = 'Backfill [[wiki-link]] connections into document_links for a world (or all worlds).';

    public function handle(): int
    {
        $query = Document::query();

        $worldArgument = $this->argument('world');
        if ($worldArgument !== null) {
            $world = ctype_digit((string) $worldArgument)
                ? World::find((int) $worldArgument)
                : World::where('slug', $worldArgument)->first();

            if ($world === null) {
                $this->error("No world matched \"{$worldArgument}\".");

                return self::FAILURE;
            }

            $query->where('world_id', $world->id);
        }

        $synced = 0;
        $query->chunkById(200, function (Collection $documents) use (&$synced): void {
            foreach ($documents as $document) {
                WikiLinks::sync($document);
                $synced++;
            }
        });

        $this->info("Re-synced wiki-links for {$synced} document(s).");

        return self::SUCCESS;
    }
}
