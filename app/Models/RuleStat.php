<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A core stat of a {@see RuleSystem} (the GM's Strength/Dexterity equivalent), referenced by key from
 * node effects and skills.
 *
 * @property-read RuleSystem $ruleSystem
 */
class RuleStat extends Model
{
    protected $fillable = [
        'rule_system_id', 'key', 'label', 'abbreviation', 'description', 'default_value', 'sort',
    ];

    protected $casts = [
        'rule_system_id' => 'int',
        'default_value' => 'int',
        'sort' => 'int',
    ];

    public function ruleSystem(): BelongsTo
    {
        return $this->belongsTo(RuleSystem::class);
    }
}
