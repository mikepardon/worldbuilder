<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node on a {@see TalentWeb}: a talent, stat bump, spell or ability. effects holds structured
 * modifiers, options holds choose-one variants, and config holds the rest (drawback, mana, rank,
 * solo, bridge targets, enhancements, glyph/colour overrides). parent_node_id + layout_role describe
 * how it is arranged: a 'child' fans off its parent, a 'ring' node circles its master, and both follow
 * their parent when it is dragged; 'master'/'adjacent' nodes are moved directly.
 *
 * @property-read TalentWeb $web
 * @property-read TalentNode|null $parent
 * @property-read Collection<int, TalentNode> $children
 * @property-read CampaignCompendiumItem|null $compendiumItem
 */
class TalentNode extends Model
{
    protected $fillable = [
        'talent_web_id', 'parent_node_id', 'compendium_item_id', 'key', 'name', 'kind', 'layout_role',
        'x', 'y', 'ring', 'gate_level', 'cost', 'description', 'effects', 'options', 'config', 'sort',
    ];

    protected $casts = [
        'talent_web_id' => 'int',
        'parent_node_id' => 'int',
        'compendium_item_id' => 'int',
        'x' => 'int',
        'y' => 'int',
        'ring' => 'int',
        'gate_level' => 'int',
        'cost' => 'int',
        'effects' => 'array',
        'options' => 'array',
        'config' => 'array',
        'sort' => 'int',
    ];

    public function web(): BelongsTo
    {
        return $this->belongsTo(TalentWeb::class, 'talent_web_id');
    }

    /** @return BelongsTo<TalentNode, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(TalentNode::class, 'parent_node_id');
    }

    /** @return HasMany<TalentNode, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(TalentNode::class, 'parent_node_id')->chaperone('parent');
    }

    /** The compendium spell/feat this node grants, if any. (Compendium items are not soft-deleted.) */
    public function compendiumItem(): BelongsTo
    {
        return $this->belongsTo(CampaignCompendiumItem::class, 'compendium_item_id');
    }
}
