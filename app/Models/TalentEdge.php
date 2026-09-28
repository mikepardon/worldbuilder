<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An undirected connection between two nodes on the same {@see TalentWeb}.
 *
 * @property-read TalentWeb $web
 * @property-read TalentNode $fromNode
 * @property-read TalentNode $toNode
 */
class TalentEdge extends Model
{
    protected $fillable = [
        'talent_web_id', 'from_node_id', 'to_node_id',
    ];

    protected $casts = [
        'talent_web_id' => 'int',
        'from_node_id' => 'int',
        'to_node_id' => 'int',
    ];

    public function web(): BelongsTo
    {
        return $this->belongsTo(TalentWeb::class, 'talent_web_id');
    }

    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(TalentNode::class, 'from_node_id');
    }

    public function toNode(): BelongsTo
    {
        return $this->belongsTo(TalentNode::class, 'to_node_id');
    }
}
