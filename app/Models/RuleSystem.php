<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProgressionMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A customisable TTRPG rules system: its stats, resources, skills, level table, node kinds and talent
 * webs. A template (world_id null) lives in the admin library for GMs to clone; otherwise it belongs
 * to a world and drives the characters built inside its campaigns.
 *
 * @property-read World|null $world
 * @property-read User|null $author
 * @property-read RuleSystem|null $templateSource
 * @property-read Collection<int, RuleStat> $stats
 * @property-read Collection<int, RuleResource> $resources
 * @property-read Collection<int, RuleSkill> $skills
 * @property-read Collection<int, RuleLevel> $levels
 * @property-read Collection<int, RuleNodeKind> $nodeKinds
 * @property-read Collection<int, TalentWeb> $webs
 * @property-read Collection<int, CharacterBuild> $builds
 */
class RuleSystem extends Model
{
    use HasFactory;

    protected $fillable = [
        'world_id', 'user_id', 'template_source_id', 'is_template', 'name', 'slug', 'description', 'settings',
    ];

    protected $casts = [
        'world_id' => 'int',
        'user_id' => 'int',
        'template_source_id' => 'int',
        'is_template' => 'boolean',
        'settings' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (RuleSystem $system) {
            if (blank($system->slug)) {
                $base = Str::slug((string) $system->name) ?: 'system';
                $slug = $base;
                $n = 2;
                while (static::where('world_id', $system->world_id)->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$n++;
                }
                $system->slug = $slug;
            }
        });
    }

    /** How points are awarded as characters advance. */
    public function progressionMode(): ProgressionMode
    {
        return ProgressionMode::tryFrom((string) data_get($this->settings, 'progression_mode')) ?? ProgressionMode::Milestone;
    }

    /** Whether the level table's points accumulate as a character levels, or apply per level only. */
    public function pointsCumulative(): bool
    {
        return (bool) data_get($this->settings, 'points_cumulative', true);
    }

    /** Points every character starts with before any level or manual award. */
    public function startingPoints(): int
    {
        return max(0, (int) data_get($this->settings, 'starting_points', 0));
    }

    /** The highest level this system defines progression for. */
    public function levelCap(): int
    {
        return max(1, (int) data_get($this->settings, 'level_cap', 20));
    }

    /**
     * Which node kind the builder's "add" menu creates for each structural role, or null to let the
     * builder fall back to a sensible default (smallest for child, largest for master, source for adjacent).
     *
     * @return array{child: string|null, adjacent: string|null, master: string|null}
     */
    public function roleKinds(): array
    {
        $roles = (array) data_get($this->settings, 'role_kinds', []);

        return [
            'child' => filled($roles['child'] ?? null) ? (string) $roles['child'] : null,
            'adjacent' => filled($roles['adjacent'] ?? null) ? (string) $roles['adjacent'] : null,
            'master' => filled($roles['master'] ?? null) ? (string) $roles['master'] : null,
        ];
    }

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function templateSource(): BelongsTo
    {
        return $this->belongsTo(RuleSystem::class, 'template_source_id');
    }

    /** @return HasMany<RuleStat, $this> */
    public function stats(): HasMany
    {
        return $this->hasMany(RuleStat::class)->chaperone();
    }

    /** @return HasMany<RuleResource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(RuleResource::class)->chaperone();
    }

    /** @return HasMany<RuleSkill, $this> */
    public function skills(): HasMany
    {
        return $this->hasMany(RuleSkill::class)->chaperone();
    }

    /** @return HasMany<RuleLevel, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(RuleLevel::class)->chaperone();
    }

    /** @return HasMany<RuleNodeKind, $this> */
    public function nodeKinds(): HasMany
    {
        return $this->hasMany(RuleNodeKind::class)->chaperone();
    }

    /** @return HasMany<TalentWeb, $this> */
    public function webs(): HasMany
    {
        return $this->hasMany(TalentWeb::class)->chaperone();
    }

    /** @return HasMany<CharacterBuild, $this> */
    public function builds(): HasMany
    {
        return $this->hasMany(CharacterBuild::class);
    }

    /** @return HasMany<Campaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
