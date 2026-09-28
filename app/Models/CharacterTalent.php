<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A node a character has allocated on its {@see CharacterBuild}: the choose-one pick (by option key)
 * and the number of enhancement ranks bought beyond the base.
 *
 * @property-read CharacterBuild $build
 * @property-read TalentNode $node
 */
class CharacterTalent extends Model
{
    protected $table = 'character_talents';

    protected $fillable = [
        'character_build_id', 'talent_node_id', 'chosen_option', 'choices', 'rank',
    ];

    protected $casts = [
        'character_build_id' => 'int',
        'talent_node_id' => 'int',
        'choices' => 'array',
        'rank' => 'int',
    ];

    public function build(): BelongsTo
    {
        return $this->belongsTo(CharacterBuild::class, 'character_build_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(TalentNode::class, 'talent_node_id');
    }
}
