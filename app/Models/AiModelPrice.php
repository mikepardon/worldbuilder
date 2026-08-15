<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-editable Claude price for one model: US cents per million tokens, input and output. Used to
 * cost each AI call at the moment it runs (see App\Support\AiPricing).
 */
class AiModelPrice extends Model
{
    protected $fillable = ['model', 'input_price', 'output_price'];

    protected $casts = [
        'input_price' => 'int',
        'output_price' => 'int',
    ];
}
