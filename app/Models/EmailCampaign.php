<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read EmailTemplate $template
 * @property-read Collection<int, EmailMessage> $messages
 */
class EmailCampaign extends Model
{
    protected $fillable = [
        'email_template_id', 'subject', 'recipient_count',
        'status', 'scheduled_at', 'sent_at',
    ];

    protected $casts = [
        'email_template_id' => 'int',
        'recipient_count' => 'int',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    /** @return HasMany<EmailMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class)->chaperone();
    }
}
