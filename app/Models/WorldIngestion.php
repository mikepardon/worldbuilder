<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A background "add knowledge to an existing world" run: the user pastes freeform notes, the AI proposes a
 * reviewable plan of {@see WorldIngestionChange}s, and — once a subset is approved — a credit-gated apply
 * pass writes them. Short-lived progress record polled by the UI; exempt from the audit/uuid traits for
 * the same reason {@see WorldBuild} is.
 *
 * @property-read World $world
 * @property-read User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WorldIngestionChange> $proposedChanges
 */
class WorldIngestion extends Model
{
    protected $fillable = [
        'world_id', 'user_id', 'status', 'source_text', 'message',
        'planned', 'applied', 'cursor', 'counts', 'log', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'world_id' => 'int',
        'user_id' => 'int',
        'planned' => 'int',
        'applied' => 'int',
        'cursor' => 'int',
        'counts' => 'array',
        'log' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<WorldIngestionChange, $this> Named to avoid Eloquent's internal $changes attribute bag. */
    public function proposedChanges(): HasMany
    {
        return $this->hasMany(WorldIngestionChange::class)->chaperone('ingestion');
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

    public function markStatus(string $status): void
    {
        $this->update(['status' => $status]);
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);
    }

    public function markCompleted(string $message): void
    {
        $this->update(['status' => 'completed', 'message' => $message, 'finished_at' => now()]);
    }

    /**
     * A compact snapshot for the review/progress UI.
     *
     * @return array<string, mixed>
     */
    public function toStatusArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'planned' => $this->planned,
            'applied' => $this->applied,
            'counts' => $this->counts ?? [],
            'message' => $this->message,
            'error' => $this->error,
            'log' => $this->log ?? [],
            'changes' => $this->proposedChanges->map(fn (WorldIngestionChange $change): array => $change->toReviewArray())->all(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
