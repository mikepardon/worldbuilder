<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A key moment within a {@see Session}: one beat the GM plans to hit, in order. Its {@see self::KINDS}
 * cover the shape of a night — where it starts, each event, the main encounter, links, the cliffhanger.
 *
 * @property-read Session $session
 */
class SessionBeat extends Model
{
    protected $table = 'session_beats';

    protected $fillable = ['session_id', 'kind', 'body', 'sort'];

    protected $casts = [
        'session_id' => 'int',
        'sort' => 'int',
    ];

    /** @var list<string> */
    public const KINDS = ['start', 'event', 'encounter', 'link', 'cliffhanger'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }
}
