<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalAttribute extends Model
{
    protected $fillable = [
        'key', 'label', 'type', 'options', 'ref_kinds', 'kinds',
        'link_label', 'inverse_label', 'required', 'visible', 'help', 'placeholder', 'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'ref_kinds' => 'array',
        'kinds' => 'array',
        'required' => 'boolean',
        'visible' => 'boolean',
    ];
}
