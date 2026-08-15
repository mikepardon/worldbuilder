<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompendiumSource extends Model
{
    protected $fillable = [
        'key', 'name', 'provider', 'item_type', 'api_url', 'enabled', 'last_run_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CompendiumItem::class, 'source_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CompendiumImportRun::class, 'source_id');
    }
}
