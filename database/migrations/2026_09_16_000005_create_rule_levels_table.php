<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rule system's progression table — one row per level. xp_required is optional (null for milestone
 * play); talent_points is the award for reaching that level, so scaling can differ per level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_system_id')->constrained('rule_systems')->cascadeOnUpdate()->cascadeOnDelete();
            $table->integer('level');
            // Cumulative XP to reach this level; null when the GM plays milestone-style.
            $table->integer('xp_required')->nullable();
            $table->integer('talent_points')->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['rule_system_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_levels');
    }
};
