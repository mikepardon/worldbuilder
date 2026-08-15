<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Webhooks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An outbound webhook a GM registers on a {@see World}: a URL that receives a signed POST whenever one
 * of its subscribed events fires (see {@see Webhooks}).
 *
 * @property-read World $world
 */
class Webhook extends Model
{
    protected $fillable = ['world_id', 'url', 'secret', 'events', 'is_active'];

    protected $casts = [
        'world_id' => 'int',
        'events' => 'array',
        'is_active' => 'boolean',
        // The HMAC signing secret; encrypted at rest.
        'secret' => 'encrypted',
    ];

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }
}
