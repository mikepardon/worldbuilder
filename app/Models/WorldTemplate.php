<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable reader layout a world defines for a document kind. An entry may pick one to change how its
 * quick facts and content are arranged, letting some locations look different from others.
 *
 * @property-read World $world
 */
class WorldTemplate extends Model
{
    /** @var array{facts: string, width: string, banner: string, fields: list<string>} */
    public const DEFAULT_LAYOUT = ['facts' => 'sidebar', 'width' => 'normal', 'banner' => 'auto', 'fields' => []];

    protected $fillable = ['world_id', 'name', 'kind', 'target', 'section', 'layout', 'is_default'];

    protected $casts = [
        'world_id' => 'int',
        'layout' => 'array',
        'is_default' => 'boolean',
    ];

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }
}
