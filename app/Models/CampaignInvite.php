<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignInvite extends Model
{
    protected $fillable = [
        'campaign_id', 'email', 'role', 'token', 'status', 'invited_by', 'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
