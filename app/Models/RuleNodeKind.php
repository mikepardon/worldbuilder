<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NodeShape;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A node type of a {@see RuleSystem} (the GM's Minor/Notable/Keystone equivalent): its default cost,
 * rendered shape/glyph/size, and any per-character cap (e.g. a keystone limit).
 *
 * @property-read RuleSystem $ruleSystem
 */
class RuleNodeKind extends Model
{
    protected $fillable = [
        'rule_system_id', 'key', 'label', 'default_cost', 'shape', 'glyph', 'size', 'colour', 'max_per_character', 'sort',
    ];

    protected $casts = [
        'rule_system_id' => 'int',
        'default_cost' => 'int',
        'shape' => NodeShape::class,
        'size' => 'int',
        'max_per_character' => 'int',
        'sort' => 'int',
    ];

    public function ruleSystem(): BelongsTo
    {
        return $this->belongsTo(RuleSystem::class);
    }
}
