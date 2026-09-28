<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of a {@see RuleSystem}'s progression table: the XP to reach a level (optional) and the talent
 * points awarded for it.
 *
 * @property-read RuleSystem $ruleSystem
 */
class RuleLevel extends Model
{
    protected $fillable = [
        'rule_system_id', 'level', 'xp_required', 'talent_points', 'notes',
    ];

    protected $casts = [
        'rule_system_id' => 'int',
        'level' => 'int',
        'xp_required' => 'int',
        'talent_points' => 'int',
    ];

    public function ruleSystem(): BelongsTo
    {
        return $this->belongsTo(RuleSystem::class);
    }
}
