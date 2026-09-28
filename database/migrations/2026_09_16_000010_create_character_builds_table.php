<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A character's build against one rule system — the pool it draws on and any GM-awarded extras. The
 * allocated nodes hang off this via character_talents. One build per (character, system).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_builds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained('characters')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('rule_system_id')->constrained('rule_systems')->cascadeOnUpdate()->cascadeOnDelete();
            // Ad-hoc points the GM awards on top of the level table (or the sole source in manual mode).
            $table->integer('manual_points')->default(0);
            // A level set for this build; when null the character's own level is used.
            $table->integer('level_override')->nullable();
            // Accumulated XP, when the system runs XP-based progression.
            $table->integer('xp')->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'rule_system_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_builds');
    }
};
