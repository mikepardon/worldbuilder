<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's private note against a {@see Session}'s recap, written from the public reader. Personal to
 * the author — never shown to anyone else.
 *
 * @property-read Session $session
 * @property-read User $user
 */
class SessionNote extends Model
{
    protected $fillable = ['session_id', 'user_id', 'body'];

    protected $casts = [
        'session_id' => 'int',
        'user_id' => 'int',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
