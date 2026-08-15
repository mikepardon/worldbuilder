<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks one background import run (currently D&D Beyond) so the import page can show live progress,
 * a running log, and the final per-type counts without holding the web request open.
 *
 * @property-read Campaign $campaign
 * @property-read User|null $user
 */
class CompendiumImport extends Model
{
    protected $fillable = [
        'world_id', 'user_id', 'source', 'status', 'types', 'homebrew', 'ddb_campaign_id',
        'processed', 'imported', 'images', 'counts', 'message', 'error', 'log', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'types' => 'array',
        'counts' => 'array',
        'log' => 'array',
        'homebrew' => 'boolean',
        'processed' => 'integer',
        'imported' => 'integer',
        'images' => 'integer',
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
    ];

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function isFinished(): bool
    {
        return $this->status === 'completed' || $this->status === 'failed';
    }

    /** Append a timestamped line to the running log and persist immediately (the UI polls this). */
    public function pushLog(string $line): void
    {
        $log = $this->log ?? [];
        $log[] = ['at' => now()->toIso8601String(), 'line' => $line];
        $this->update(['log' => $log, 'message' => $line]);
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'running', 'started_at' => now()]);
    }

    public function markCompleted(): void
    {
        $this->update(['status' => 'completed', 'message' => 'Import complete.', 'finished_at' => now()]);
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);
    }

    /**
     * A compact, frontend-friendly snapshot for the progress page (no sensitive fields).
     *
     * @return array<string, mixed>
     */
    public function toStatusArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'types' => $this->types,
            'processed' => $this->processed,
            'imported' => $this->imported,
            'images' => $this->images,
            'counts' => $this->counts ?? [],
            'message' => $this->message,
            'error' => $this->error,
            'log' => $this->log ?? [],
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
