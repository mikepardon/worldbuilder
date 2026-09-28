<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property-read Collection<int, EmailCampaign> $campaigns
 */
class EmailTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'reference', 'display_name', 'subject', 'description',
        'mjml', 'html',
        'from_name', 'from_mailbox', 'reply_to_name', 'reply_to_mailbox',
    ];

    protected $casts = [];

    protected static function booted(): void
    {
        static::creating(function (EmailTemplate $template) {
            if (empty($template->reference)) {
                $template->reference = Str::slug($template->display_name) ?: 'template';
            }
        });
    }

    /** @return HasMany<EmailCampaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(EmailCampaign::class)->chaperone();
    }
}
