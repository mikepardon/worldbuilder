<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompendiumItem extends Model
{
    protected $fillable = [
        'source_id', 'item_type', 'slug', 'name', 'summary',
        'document', 'fields', 'data', 'fingerprint', 'version', 'visible',
    ];

    protected $casts = [
        'fields' => 'array',
        'data' => 'array',
        'visible' => 'boolean',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(CompendiumSource::class, 'source_id');
    }
}
