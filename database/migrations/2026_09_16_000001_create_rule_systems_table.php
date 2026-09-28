<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customisable TTRPG rules system: its stats, resources, skills, level table and talent webs. A
 * system with a null world_id is a global template (built by admins) that GMs can clone into a world;
 * otherwise it belongs to that world. template_source_id records which template a copy came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_systems', function (Blueprint $table) {
            $table->id();
            // Null for global templates in the admin library; otherwise the owning world.
            $table->foreignId('world_id')->nullable()->constrained('worlds')->cascadeOnUpdate()->cascadeOnDelete();
            // The author (an admin for templates, the GM for world systems); kept for provenance.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            // Which template this system was cloned from, if any (unlinked, not deleted, with its source).
            $table->foreignId('template_source_id')->nullable()->constrained('rule_systems')->cascadeOnUpdate()->nullOnDelete();
            $table->boolean('is_template')->default(false);
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            // progression_mode, points_cumulative, starting_points, keystone_cap, level_cap, enforcement.
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index('world_id');
            $table->index('is_template');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_systems');
    }
};
