<?php

namespace App\Services;

use App\Models\CompendiumImportRun;
use App\Models\CompendiumItem;
use App\Models\CompendiumSource;
use App\Services\Compendium\CompendiumProvider;
use App\Services\Compendium\Dnd5eApiProvider;
use App\Services\Compendium\Open5eProvider;
use App\Support\CompendiumFields;
use App\Support\Statblock;
use Illuminate\Support\Str;
use Throwable;

/**
 * Walks an Open5e source's pages into the global compendium library, recording a run with
 * added/updated/unchanged tallies. Change detection is by SHA-256 fingerprint of the raw record.
 */
class CompendiumImporter
{
    // Bump when the record→item mapping changes, so a re-import re-processes items whose raw record
    // is unchanged (the fingerprint includes this, so old entries no longer match and get rebuilt).
    private const MAPPING_VERSION = 2;

    /** Resolve the provider for a source (defaults to Open5e). */
    public function provider(CompendiumSource $source): CompendiumProvider
    {
        return match ($source->provider) {
            'dnd5eapi' => app(Dnd5eApiProvider::class),
            default => app(Open5eProvider::class),
        };
    }

    /**
     * Upsert one batch of already-fetched raw records, returning the tally. Used by the queued importer
     * job, which fetches records a chunk at a time via the provider.
     *
     * @param  list<array<string, mixed>>  $records
     * @return array{added: int, updated: int, unchanged: int}
     */
    public function ingest(CompendiumSource $source, array $records): array
    {
        $provider = $this->provider($source);
        $added = $updated = $unchanged = 0;

        foreach ($records as $raw) {
            match ($this->upsert($source, $provider, $raw)) {
                'added' => $added++,
                'updated' => $updated++,
                default => $unchanged++,
            };
        }

        return ['added' => $added, 'updated' => $updated, 'unchanged' => $unchanged];
    }

    public function run(CompendiumSource $source, int $maxPages = 100): CompendiumImportRun
    {
        $run = $source->runs()->create(['status' => 'running', 'started_at' => now()]);

        $added = $updated = $unchanged = 0;

        try {
            $provider = $this->provider($source);

            foreach ($provider->records($source, $maxPages) as $raw) {
                match ($this->upsert($source, $provider, $raw)) {
                    'added' => $added++,
                    'updated' => $updated++,
                    default => $unchanged++,
                };
            }

            $source->update(['last_run_at' => now()]);
            $run->update([
                'status' => 'complete',
                'added' => $added,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Record the failure against the run for the user, but also surface it to Sentry — a broken
            // import (provider error, mapping bug, malformed record) shouldn't be visible only as a failed row.
            report($e);

            $run->update([
                'status' => 'failed',
                'added' => $added,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'error' => Str::limit($e->getMessage(), 480),
                'finished_at' => now(),
            ]);
        }

        return $run->fresh();
    }

    /** @return 'added'|'updated'|'unchanged' */
    protected function upsert(CompendiumSource $source, CompendiumProvider $provider, array $raw): string
    {
        $name = $raw['name'] ?? 'Untitled';
        $slug = $provider->slug($raw);
        if (! $slug) {
            return 'unchanged';
        }

        $fingerprint = hash('sha256', self::MAPPING_VERSION.'|'.$source->provider.'|'.json_encode($raw));
        $existing = CompendiumItem::where('item_type', $source->item_type)->where('slug', $slug)->first();

        if ($existing && $existing->fingerprint === $fingerprint) {
            return 'unchanged';
        }

        $preview = $provider->preview($source->item_type, $raw);

        // Monsters build a structured stat block; other types map to their CompendiumFields schema.
        // Both regenerate the Markdown document so imports arrive with real, renderable content.
        $fields = [];
        $document = "# {$name}\n\n";
        if ($source->item_type === 'monster' && ($block = $provider->toBlock($source->item_type, $raw))) {
            $fields = ['block' => $block];
            $document = Statblock::toMarkdown($block, $name);
        } elseif (CompendiumFields::has($source->item_type)) {
            $fields = $provider->toFields($source->item_type, $raw);
            $document = CompendiumFields::toMarkdown($source->item_type, $fields, $name);
        }

        $attrs = [
            'source_id' => $source->id,
            'item_type' => $source->item_type,
            'slug' => $slug,
            'name' => $name,
            'summary' => $preview['summary'] ?: null,
            'document' => $document,
            'fields' => $fields,
            'data' => $raw,
            'fingerprint' => $fingerprint,
        ];

        if ($existing) {
            $existing->update([...$attrs, 'version' => $existing->version + 1]);

            return 'updated';
        }

        CompendiumItem::create($attrs);

        return 'added';
    }
}
