<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read Collection<int, EmailMessage> $messages
 */
class EmailSubscriber extends Model
{
    protected $fillable = ['email', 'name', 'subscribed', 'unsubscribed_at', 'variables'];

    protected $casts = [
        'subscribed' => 'boolean',
        'unsubscribed_at' => 'datetime',
        'variables' => 'array',
    ];

    /** @return HasMany<EmailMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class)->chaperone();
    }
}
