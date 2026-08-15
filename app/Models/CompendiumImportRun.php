<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompendiumImportRun extends Model
{
    protected $fillable = [
        'source_id', 'status', 'added', 'updated', 'unchanged', 'cursor', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function isFinished(): bool
    {
        return $this->status === 'complete' || $this->status === 'failed';
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(CompendiumSource::class, 'source_id');
    }
}
