<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency guard for Stripe Checkout fulfilment. A completed checkout can be applied from two
 * places — the webhook and the on-return confirmation — so we record each session id once to make
 * sure a purchase (especially a credit top-up) is never granted twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_checkouts', function (Blueprint $table) {
            $table->id();
            $table->string('checkout_id')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_checkouts');
    }
};
