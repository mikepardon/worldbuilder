<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentLink extends Model
{
    protected $fillable = [
        'world_id', 'from_document_id', 'to_document_id', 'label', 'relationship', 'source',
    ];

    protected $casts = [
        'world_id' => 'int',
        'from_document_id' => 'int',
        'to_document_id' => 'int',
    ];

    public function world(): BelongsTo
    {
        return $this->belongsTo(World::class);
    }

    public function fromDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'from_document_id');
    }

    public function toDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'to_document_id');
    }
}
