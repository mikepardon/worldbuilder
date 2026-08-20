<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A queued AI generation the browser polls for: created `pending` when a chat message is sent, moved to
 * `done` (with a result payload) or `failed` (with a message) by the job that runs the model call. Keyed
 * publicly by uuid. Short-lived; not audited.
 *
 * @property-read User $user
 */
class AiRequest extends Model
{
    protected $fillable = ['uuid', 'user_id', 'feature', 'status', 'result', 'error'];

    protected $casts = [
        'user_id' => 'int',
        'result' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param array<string, mixed> $result */
    public function markDone(array $result): void
    {
        $this->update(['status' => 'done', 'result' => $result, 'error' => null]);
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => 'failed', 'error' => $error]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toStatusArray(): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'result' => $this->result,
            'error' => $this->error,
        ];
    }
}
