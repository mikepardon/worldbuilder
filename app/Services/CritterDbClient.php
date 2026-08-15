<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client over CritterDB's public API (https://critterdb.com). Only published bestiaries are
 * reachable without authentication; the creatures endpoint is paginated at 25 records per page.
 */
class CritterDbClient
{
    private const BASE = 'https://critterdb.com/api';

    private const PAGE_SIZE = 25;

    /** Hard cap so a huge (or misbehaving) bestiary cannot loop forever. */
    private const MAX_PAGES = 40;

    /**
     * Fetch every creature in a published bestiary, following pagination.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException when CritterDB is unreachable or the bestiary is not public
     */
    public function creatures(string $bestiaryId): array
    {
        $creatures = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = Http::acceptJson()->timeout(20)
                ->get(self::BASE."/publishedbestiaries/{$bestiaryId}/creatures/{$page}");

            if ($response->status() === 404) {
                throw new RuntimeException('That bestiary could not be found. Make sure it is published (public) on CritterDB.');
            }

            if (! $response->ok()) {
                throw new RuntimeException('CritterDB did not respond. It is often offline — try again shortly, or paste the JSON export instead.');
            }

            $batch = $response->json();
            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $creature) {
                if (is_array($creature)) {
                    $creatures[] = $creature;
                }
            }

            if (count($batch) < self::PAGE_SIZE) {
                break;
            }
        }

        return $creatures;
    }
}
