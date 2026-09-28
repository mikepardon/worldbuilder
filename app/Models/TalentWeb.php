<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One canvas of nodes and edges within a {@see RuleSystem}. layout carries builder canvas config
 * (centre, ring radii) for the optional radial auto-arrange.
 *
 * @property-read RuleSystem $ruleSystem
 * @property-read Collection<int, TalentNode> $nodes
 * @property-read Collection<int, TalentEdge> $edges
 */
class TalentWeb extends Model
{
    protected $fillable = [
        'rule_system_id', 'name', 'slug', 'description', 'layout', 'sort',
    ];

    protected $casts = [
        'rule_system_id' => 'int',
        'layout' => 'array',
        'sort' => 'int',
    ];

    protected static function booted(): void
    {
        static::creating(function (TalentWeb $web) {
            if (blank($web->slug)) {
                $base = Str::slug((string) $web->name) ?: 'web';
                $slug = $base;
                $n = 2;
                while (static::where('rule_system_id', $web->rule_system_id)->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$n++;
                }
                $web->slug = $slug;
            }
        });
    }

    public function ruleSystem(): BelongsTo
    {
        return $this->belongsTo(RuleSystem::class);
    }

    /** @return HasMany<TalentNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(TalentNode::class)->chaperone('web');
    }

    /** @return HasMany<TalentEdge, $this> */
    public function edges(): HasMany
    {
        return $this->hasMany(TalentEdge::class)->chaperone('web');
    }
}
