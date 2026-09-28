<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read EmailSubscriber|null $subscriber
 * @property-read EmailCampaign|null $campaign
 */
class EmailMessage extends Model
{
    protected $fillable = [
        'email_subscriber_id', 'email_campaign_id',
        'recipient_email', 'template_reference', 'subject', 'domain',
        'provider_message_id', 'status',
        'sent_at', 'delivered_at', 'opened_at', 'clicked_at', 'bounced_at',
        'clicked_links',
    ];

    protected $casts = [
        'email_subscriber_id' => 'int',
        'email_campaign_id' => 'int',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'bounced_at' => 'datetime',
        'clicked_links' => 'array',
    ];

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(EmailSubscriber::class, 'email_subscriber_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }
}
