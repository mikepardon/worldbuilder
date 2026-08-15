<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Records each Stripe Checkout session that has been fulfilled, so it is never fulfilled twice. */
class ProcessedCheckout extends Model
{
    protected $fillable = ['checkout_id'];
}
