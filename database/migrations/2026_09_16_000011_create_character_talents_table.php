<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A node a character has allocated on its build. chosen_option records a choose-one pick (by option
 * key); rank records how many enhancement ranks have been bought beyond the base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_talents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_build_id')->constrained('character_builds')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('talent_node_id')->constrained('talent_nodes')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('chosen_option')->nullable();
            $table->integer('rank')->default(0);
            $table->timestamps();

            $table->index('character_build_id');
            $table->unique(['character_build_id', 'talent_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_talents');
    }
};
