<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI cost analytics. `ai_model_prices` holds the admin-editable Claude prices (US cents per million
 * tokens, input/output). `ai_usage_events` logs every metered AI call — its feature, world, user,
 * model, token counts, and the cost snapshotted at the price in effect then (stored in nanodollars,
 * 1e-9 USD, so per-call sub-cent costs stay exact and sum without rounding loss).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_prices', function (Blueprint $table) {
            $table->id();
            $table->string('model')->unique();
            $table->unsignedInteger('input_price');  // US cents per million input tokens
            $table->unsignedInteger('output_price'); // US cents per million output tokens
            $table->timestamps();
        });

        // Seed from the config defaults so costing works out of the box.
        $now = now();
        $rows = collect(config('ai.prices', []))
            ->map(fn (array $price, string $model) => [
                'model' => $model,
                'input_price' => (int) $price['input'],
                'output_price' => (int) $price['output'],
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();
        if ($rows !== []) {
            DB::table('ai_model_prices')->insert($rows);
        }

        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('world_id')->nullable()->constrained('worlds')->nullOnDelete();
            $table->string('feature');           // assistant_ask, assistant_draft, session_writeup, generator, …
            $table->string('detail')->nullable(); // e.g. generator kind, compendium item type
            $table->string('model');
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');
            $table->unsignedBigInteger('cost_nanos'); // cost in nanodollars (1e-9 USD)
            $table->timestamps();
            $table->index('feature');
            $table->index(['world_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
        Schema::dropIfExists('ai_model_prices');
    }
};
