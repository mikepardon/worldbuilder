<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A character's build against one {@see RuleSystem}: the points it may spend and the nodes it has
 * allocated. The effective level is level_override, else the character's own level.
 *
 * @property-read Character $character
 * @property-read RuleSystem $ruleSystem
 * @property-read Collection<int, CharacterTalent> $talents
 */
class CharacterBuild extends Model
{
    protected $fillable = [
        'character_id', 'rule_system_id', 'manual_points', 'level_override', 'xp',
    ];

    protected $casts = [
        'character_id' => 'int',
        'rule_system_id' => 'int',
        'manual_points' => 'int',
        'level_override' => 'int',
        'xp' => 'int',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function ruleSystem(): BelongsTo
    {
        return $this->belongsTo(RuleSystem::class);
    }

    /** @return HasMany<CharacterTalent, $this> */
    public function talents(): HasMany
    {
        return $this->hasMany(CharacterTalent::class)->chaperone('build');
    }
}
