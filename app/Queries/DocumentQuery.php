<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\DocumentSummaryData;
use App\Models\Document;
use App\Models\World;
use App\Support\Viewer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Read side for world entries. Owns visibility scoping so no controller re-implements "hide private
 * entries from players" — pass a {@see Viewer} and the right rows come back, every time.
 */
class DocumentQuery
{
    /**
     * The document models in a world this viewer may see. Returns models (not DTOs) because the reader
     * still needs them for section counts, facts, and the timeline.
     *
     * @return EloquentCollection<int, Document>
     */
    public function visibleTo(World $world, Viewer $viewer): EloquentCollection
    {
        $query = $world->documents();

        if (! $viewer->seesPrivate()) {
            $query->where('is_private', false)
                ->where(fn (Builder $builder) => $builder->whereNull('publish_at')->orWhere('publish_at', '<=', now()));
        }

        return $query->get();
    }

    /** How many entries this viewer may see — cheap existence-style gate for optional reader nav links. */
    public function visibleCount(World $world, Viewer $viewer): int
    {
        $query = $world->documents();

        if (! $viewer->seesPrivate()) {
            $query->where('is_private', false)
                ->where(fn (Builder $builder) => $builder->whereNull('publish_at')->orWhere('publish_at', '<=', now()));
        }

        return $query->count();
    }

    /**
     * Visible entries as summary DTOs — the shape a list surface or API resolver consumes.
     *
     * @return Collection<int, DocumentSummaryData>
     */
    public function summariesFor(World $world, Viewer $viewer): Collection
    {
        return $this->visibleTo($world, $viewer)->map(DocumentSummaryData::fromModel(...));
    }

    /**
     * Viewer-scoped full-text search across a world's entries (title, summary, content, tags). Uses
     * LOWER(...) LIKE so it behaves the same on SQLite and Postgres; bindings are parameterised.
     *
     * @return Collection<int, DocumentSummaryData>
     */
    public function search(World $world, Viewer $viewer, string $term): Collection
    {
        $term = trim($term);
        if ($term === '') {
            return collect();
        }

        $like = '%'.mb_strtolower($term).'%';
        $query = $world->documents();

        if (! $viewer->seesPrivate()) {
            $query->where('is_private', false)
                ->where('hide_from_search', false)
                ->where(fn (Builder $builder) => $builder->whereNull('publish_at')->orWhere('publish_at', '<=', now()));
        }

        $query->where(function (Builder $builder) use ($like): void {
            $builder->whereRaw('LOWER(title) LIKE ?', [$like])
                ->orWhereRaw('LOWER(summary) LIKE ?', [$like])
                ->orWhereRaw('LOWER(content) LIKE ?', [$like])
                ->orWhereRaw('LOWER(tags) LIKE ?', [$like]);
        });

        return $query->orderBy('title')->limit(100)->get()->map(DocumentSummaryData::fromModel(...));
    }
}
