<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A skill of a {@see RuleSystem} (the GM's Athletics/Stealth equivalent), optionally governed by a
 * stat named by key.
 *
 * @property-read RuleSystem $ruleSystem
 */
class RuleSkill extends Model
{
    protected $fillable = [
        'rule_system_id', 'key', 'label', 'governing_stat_key', 'description', 'sort',
    ];

    protected $casts = [
        'rule_system_id' => 'int',
        'sort' => 'int',
    ];

    public function ruleSystem(): BelongsTo
    {
        return $this->belongsTo(RuleSystem::class);
    }
}
