<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A campaign: a playthrough inside a {@see World}. It owns the players who join it, its sessions, the
 * battle rooms run for it, and the player characters. It draws on the world's lore, compendium and maps.
 *
 * @property-read World $world
 */
class Campaign extends Model
{
    use HasFactory;

    protected $fillable = ['world_id', 'name', 'slug', 'code', 'description', 'visibility', 'game_system', 'recap_facts', 'recap_instructions'];

    /** This campaign's game system, falling back to the world default. */
    public function gameSystem(): ?string
    {
        return filled($this->game_system) ? $this->game_system : $this->world->defaultGameSystem();
    }

    protected $casts = [
        'world_id' => 'int',
        'recap_facts' => 'array',
        'recap_instructions' => 'array',
        // Carries a secret token; Laravel encrypts it at rest and decrypts on access.
        'discord_webhook' => 'encrypted',
    ];

    /** The Discord webhook a notification for this campaign should post to: its own, else the world's. */
    public function effectiveDiscordWebhook(): ?string
    {
        return filled($this->discord_webhook) ? $this->discord_webhook : $this->world->discord_webhook;
    }

    protected static function booted(): void
    {
        static::creating(function (Campaign $campaign) {
            if (empty($campaign->code)) {
                do {
                    $code = Str::lower(Str::random(6));
                } while (static::where('code', $code)->exists());
                $campaign->code = $code;
            }

            if (empty($campaign->slug)) {
                $base = Str::slug((string) $campaign->name) ?: 'campaign';
                $slug = $base;
                $n = 2;
                while (static::where('world_id', $campaign->world_id)->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$n++;
                }
                $campaign->slug = $slug;
            }
        });
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(CampaignMember::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(CampaignInvite::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    /** @return HasMany<ScheduleEvent, $this> */
    public function scheduleEvents(): HasMany
    {
        return $this->hasMany(ScheduleEvent::class)->chaperone();
    }
}
