<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The skills of a rule system — the GM's equivalent of Athletics/Stealth. Each optionally names the
 * stat that governs it (by key) so the character sheet can show a derived value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_system_id')->constrained('rule_systems')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            // The rule_stats.key that governs this skill, if any (kept loose: stats may be renamed).
            $table->string('governing_stat_key')->nullable();
            $table->text('description')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->unique(['rule_system_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_skills');
    }
};
