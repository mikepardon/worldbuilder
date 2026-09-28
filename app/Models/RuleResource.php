<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pooled resource of a {@see RuleSystem} (the GM's Life/Mana equivalent). Node effects add flat or
 * percentage bonuses to it by key.
 *
 * @property-read RuleSystem $ruleSystem
 */
class RuleResource extends Model
{
    protected $fillable = [
        'rule_system_id', 'key', 'label', 'description', 'base_value', 'colour', 'sort',
    ];

    protected $casts = [
        'rule_system_id' => 'int',
        'base_value' => 'int',
        'sort' => 'int',
    ];

    public function ruleSystem(): BelongsTo
    {
        return $this->belongsTo(RuleSystem::class);
    }
}
