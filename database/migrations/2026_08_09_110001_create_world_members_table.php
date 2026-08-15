<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Co-authors of a world: other accounts the owner invites to help build its content (entries,
 * compendium, maps). Distinct from campaign players — this is edit access to the world itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('world_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_id')->constrained('worlds')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('role')->default('editor');
            $table->timestamps();
            $table->unique(['world_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('world_members');
    }
};
