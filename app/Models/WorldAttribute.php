<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A world's own custom field definition — layered over the platform's {@see GlobalAttribute} defaults.
 * A world attribute with the same key as a global one overrides it (or hides it when not visible);
 * a new key adds a field.
 *
 * @property-read World $world
 */
class WorldAttribute extends Model
{
    protected $fillable = [
        'world_id', 'key', 'label', 'type', 'multiple', 'options', 'ref_kinds', 'kinds',
        'link_label', 'inverse_label', 'required', 'visible', 'help', 'placeholder', 'sort_order',
    ];

    protected $casts = [
        'world_id' => 'int',
        'options' => 'array',
        'ref_kinds' => 'array',
        'kinds' => 'array',
        'required' => 'boolean',
        'visible' => 'boolean',
        'multiple' => 'boolean',
    ];

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }
}
