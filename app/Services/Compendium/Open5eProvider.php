<?php

declare(strict_types=1);

namespace App\Services\Compendium;

use App\Models\CompendiumSource;
use App\Services\Open5eClient;
use App\Support\Statblock;
use Illuminate\Support\Str;

/** Open5e provider: each list page already contains full records, so we just follow `next`. */
class Open5eProvider implements CompendiumProvider
{
    public function __construct(protected Open5eClient $open5e) {}

    public function records(CompendiumSource $source, int $maxPages): iterable
    {
        $url = $source->api_url;
        $pages = 0;
        while ($url && $pages < $maxPages) {
            $page = $this->open5e->page($url);
            foreach ($page['results'] as $raw) {
                yield $raw;
            }
            $url = $page['next'] ?: null;
            $pages++;
        }
    }

    public function chunk(CompendiumSource $source, ?string $cursor): array
    {
        // The cursor is simply the next page URL to fetch (starting from the source's own URL). Each
        // Open5e page already holds full records, so one page is one chunk.
        $page = $this->open5e->page($cursor ?? $source->api_url);

        return [
            'records' => $page['results'] ?? [],
            'next' => ($page['next'] ?? null) ?: null,
        ];
    }

    public function slug(array $raw): ?string
    {
        return $raw['slug'] ?? (($raw['name'] ?? '') !== '' ? Str::slug($raw['name']) : null);
    }

    public function preview(string $itemType, array $raw): array
    {
        return Open5eClient::preview($itemType, $raw);
    }

    public function toBlock(string $itemType, array $raw): ?array
    {
        return $itemType === 'monster' ? Statblock::fromOpen5e($raw) : null;
    }

    public function toFields(string $itemType, array $raw): array
    {
        return Open5eClient::toFields($itemType, $raw);
    }
}
