<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Plans;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'ddb_cobalt', 'plan', 'ai_credit_balance', 'avatar_path'])]
#[Hidden(['password', 'remember_token', 'ddb_cobalt', 'stripe_customer_id', 'stripe_subscription_id', 'avatar_path'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var list<string> */
    protected $appends = ['avatar_url'];

    /** Public URL of the user's uploaded avatar, or null if they have none. */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => filled($this->avatar_path)
            ? Storage::disk(config('media.disk'))->url($this->avatar_path)
            : null);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            // Personal D&D Beyond cobalt token; encrypted at rest.
            'ddb_cobalt' => 'encrypted',
            'ai_credit_balance' => 'integer',
            'daily_ai_used' => 'integer',
            'daily_ai_reset_on' => 'date',
            'pending_plan_at' => 'datetime',
            'avatar_size' => 'integer',
        ];
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * The user's subscription plan entitlements. Named `planConfig` (not `plan`) to avoid colliding
     * with the `plan` column, which Eloquent would otherwise treat as a relationship accessor.
     *
     * @return array<string, mixed>
     */
    public function planConfig(): array
    {
        return Plans::for(is_string($this->plan) ? $this->plan : 'free');
    }

    /** How many worlds this account may own (per plan). */
    public function worldLimit(): int
    {
        return (int) $this->planConfig()['worlds'];
    }

    /** Free AI credits granted each day by the plan. */
    public function dailyAiAllowance(): int
    {
        return (int) $this->planConfig()['daily_credits'];
    }

    /** Whether the plan includes publishing a world on a custom domain. */
    public function canUseCustomDomain(): bool
    {
        return (bool) $this->planConfig()['custom_domain'];
    }

    /** Total media storage the account may use, in bytes (per plan). */
    public function storageLimitBytes(): int
    {
        return (int) round((float) $this->planConfig()['storage_gb'] * 1024 ** 3);
    }

    /** Bytes this account has stored: uploaded media, their avatar, and any session-recording audio. */
    public function storageUsedBytes(): int
    {
        return (int) Media::where('user_id', $this->id)->sum('size')
            + (int) $this->avatar_size
            + (int) Recap::where('user_id', $this->id)->sum('size_bytes');
    }

    /** Storage headroom left, in bytes (never negative). */
    public function storageRemainingBytes(): int
    {
        return max(0, $this->storageLimitBytes() - $this->storageUsedBytes());
    }

    /** Whether this account has room to store $bytes more (admins are unlimited, as with the world limit). */
    public function canStore(int $bytes): bool
    {
        return $this->isAdmin() || $bytes <= $this->storageRemainingBytes();
    }

    /** Daily credits used today (0 if the daily window has rolled over). */
    public function aiUsedToday(): int
    {
        return $this->daily_ai_reset_on?->toDateString() === now()->toDateString()
            ? (int) $this->daily_ai_used
            : 0;
    }

    /** Free daily credits left + any purchased top-up balance. */
    public function aiCreditsRemaining(): int
    {
        return max(0, $this->dailyAiAllowance() - $this->aiUsedToday()) + (int) $this->ai_credit_balance;
    }

    public function canSpendAiCredit(): bool
    {
        return $this->canSpendAiCredits(1);
    }

    /** Whether the account has at least $credits available (daily allowance + top-up balance). */
    public function canSpendAiCredits(int $credits): bool
    {
        return $this->aiCreditsRemaining() >= max(1, $credits);
    }

    /** Spend one credit. */
    public function spendAiCredit(): bool
    {
        return $this->spendAiCredits(1);
    }

    /** Spend $credits — daily allowance first, then the top-up balance. Returns false if not enough. */
    public function spendAiCredits(int $credits): bool
    {
        $credits = max(1, $credits);
        if (! $this->canSpendAiCredits($credits)) {
            return false;
        }

        $today = now()->toDateString();
        if ($this->daily_ai_reset_on?->toDateString() !== $today) {
            $this->daily_ai_used = 0;
            $this->daily_ai_reset_on = $today;
        }

        $dailyLeft = max(0, $this->dailyAiAllowance() - (int) $this->daily_ai_used);
        $fromDaily = min($credits, $dailyLeft);

        $this->daily_ai_used = (int) $this->daily_ai_used + $fromDaily;
        $this->ai_credit_balance = max(0, (int) $this->ai_credit_balance - ($credits - $fromDaily));

        $this->save();

        return true;
    }

    /** Worlds this user owns / runs as GM. */
    public function worlds(): HasMany
    {
        return $this->hasMany(World::class);
    }

    /** Campaign memberships (campaigns this user plays in). */
    public function memberships(): HasMany
    {
        return $this->hasMany(CampaignMember::class);
    }
}
