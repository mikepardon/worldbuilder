<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable block set a world defines once and references from many templates (a shared footer, CTA,
 * legend, …). Editing it updates everywhere it's referenced, since templates store only its id.
 *
 * @property-read World $world
 */
class WorldBlock extends Model
{
    protected $fillable = ['world_id', 'name', 'layout'];

    protected $casts = [
        'world_id' => 'int',
        'layout' => 'array',
    ];

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }
}
