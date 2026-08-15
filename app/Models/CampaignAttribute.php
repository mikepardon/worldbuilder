<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignAttribute extends Model
{
    protected $fillable = [
        'world_id', 'key', 'label', 'type', 'options',
        'ref_kinds', 'kinds', 'required', 'help', 'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'ref_kinds' => 'array',
        'kinds' => 'array',
        'required' => 'boolean',
    ];

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }
}
