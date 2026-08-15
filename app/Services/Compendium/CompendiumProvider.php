<?php

declare(strict_types=1);

namespace App\Services\Compendium;

use App\Models\CompendiumSource;

/**
 * A source of compendium records (Open5e, dnd5eapi, …). Providers know how to walk a source's listing
 * and how to map one raw record into our shapes; CompendiumImporter is provider-agnostic.
 */
interface CompendiumProvider
{
    /**
     * Yield raw records (each a complete record) for a source, up to a page cap.
     *
     * @return iterable<array<string, mixed>>
     */
    public function records(CompendiumSource $source, int $maxPages): iterable;

    /**
     * Fetch one resumable chunk of raw records so an import can run across several short queued jobs.
     * $cursor is opaque (null to start); the returned `next` is the cursor for the following chunk, or
     * null when the source is exhausted.
     *
     * @return array{records: list<array<string, mixed>>, next: string|null}
     */
    public function chunk(CompendiumSource $source, ?string $cursor): array;

    /** A stable slug for a record (used for de-duplication + change detection). */
    public function slug(array $raw): ?string;

    /**
     * Pick-list preview.
     *
     * @return array{slug: string, name: string, summary: string, meta: string}
     */
    public function preview(string $itemType, array $raw): array;

    /**
     * A structured 5e stat block for monsters, or null when the record isn't a statable creature.
     *
     * @return array<string, mixed>|null
     */
    public function toBlock(string $itemType, array $raw): ?array;

    /**
     * Structured CompendiumFields values for a non-monster type.
     *
     * @return array<string, string>
     */
    public function toFields(string $itemType, array $raw): array;
}
