<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rollable, searchable random table a world defines (encounters, loot, weather…). Each row covers a
 * range on the table's die; a row may also link to another table so one roll can chain into the next.
 *
 * @property-read World $world
 */
class RollTable extends Model
{
    protected $fillable = ['world_id', 'name', 'description', 'die', 'rows', 'is_private'];

    protected $casts = [
        'world_id' => 'int',
        'die' => 'int',
        'rows' => 'array',
        'is_private' => 'boolean',
    ];

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }
}
