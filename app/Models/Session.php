<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A session within a {@see Campaign}: the GM's prep document for a play session. Stored in
 * `campaign_sessions` (the plain `sessions` name is taken by Laravel's framework session table). Its
 * post-play analysis lives separately in a {@see Recap}, one per session.
 *
 * @property-read Campaign $campaign
 * @property-read Recap|null $recap
 * @property-read Collection<int, User> $attendees
 */
class Session extends Model
{
    protected $table = 'campaign_sessions';

    protected $fillable = ['campaign_id', 'title', 'slug', 'summary', 'body', 'held_on', 'sort', 'is_private'];

    protected $casts = [
        'campaign_id' => 'int',
        'held_on' => 'date',
        'sort' => 'int',
        'is_private' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Session $session) {
            if (empty($session->slug)) {
                $base = Str::slug((string) $session->title) ?: 'session';
                $slug = $base;
                $n = 2;
                while (static::where('campaign_id', $session->campaign_id)->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$n++;
                }
                $session->slug = $slug;
            }
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return HasOne<Recap, $this> */
    public function recap(): HasOne
    {
        return $this->hasOne(Recap::class)->chaperone();
    }

    /** The player accounts who attended this session. @return BelongsToMany<User, $this> */
    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'session_attendees', 'session_id', 'user_id')->withTimestamps();
    }

    /** @return HasMany<SessionNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(SessionNote::class)->chaperone();
    }
}
