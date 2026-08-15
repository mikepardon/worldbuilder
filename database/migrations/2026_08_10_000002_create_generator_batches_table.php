<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved run of the AI generators: the kind, focus and options a GM asked for, plus the results, so
 * they can reopen a batch later, tweak a result, and import it. Items are stored as JSON — each carries
 * a title/detail/description and, for stat-blocked kinds, a structured `block`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generator_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_id')->constrained('worlds')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind');
            $table->string('context')->nullable();
            $table->jsonb('options');
            $table->jsonb('items');
            $table->timestamps();
            $table->index(['world_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generator_batches');
    }
};
