<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A background run that populates a world with wiki entries generated from its seed notes. Short-lived
 * progress record (like {@see CompendiumImport}) polled by the dashboard; exempt from the audit/uuid
 * traits for the same reason those records are.
 *
 * @property-read World $world
 * @property-read User $user
 */
class WorldBuild extends Model
{
    protected $fillable = [
        'world_id', 'user_id', 'status', 'message', 'planned', 'outline', 'created', 'cursor', 'counts', 'log', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'world_id' => 'int',
        'user_id' => 'int',
        'planned' => 'int',
        'outline' => 'array',
        'created' => 'int',
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

    public function isFinished(): bool
    {
        return $this->status === 'completed' || $this->status === 'failed';
    }

    public function isActive(): bool
    {
        return $this->status === 'queued' || $this->status === 'running';
    }

    /** Append a timestamped line to the running log and persist immediately (the dashboard polls this). */
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

    public function markCompleted(string $message): void
    {
        $this->update(['status' => 'completed', 'message' => $message, 'finished_at' => now()]);
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);
    }

    /**
     * A compact snapshot for the dashboard's progress card.
     *
     * @return array<string, mixed>
     */
    public function toStatusArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'planned' => $this->planned,
            'created' => $this->created,
            'counts' => $this->counts ?? [],
            'message' => $this->message,
            'error' => $this->error,
            'log' => $this->log ?? [],
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
