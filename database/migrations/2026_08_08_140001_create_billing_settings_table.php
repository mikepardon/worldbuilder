<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single-row store for Stripe billing configuration, managed from the admin area. Both the sandbox
 * and live key sets are held at once; `mode` selects which is active. Secret + webhook values are
 * encrypted at rest via the model's casts; publishable keys and price IDs are not secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->string('mode')->default('sandbox'); // sandbox | live

            $table->text('test_publishable_key')->nullable();
            $table->text('test_secret_key')->nullable();
            $table->text('test_webhook_secret')->nullable();
            $table->text('test_price_basic')->nullable();
            $table->text('test_price_pro')->nullable();

            $table->text('live_publishable_key')->nullable();
            $table->text('live_secret_key')->nullable();
            $table->text('live_webhook_secret')->nullable();
            $table->text('live_price_basic')->nullable();
            $table->text('live_price_pro')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_settings');
    }
};
