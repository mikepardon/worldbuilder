<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('map_id')->constrained()->cascadeOnDelete();
            // Optional link to a world compendium entry (a monster/NPC stat block).
            $table->foreignId('compendium_item_id')->nullable()->constrained('campaign_compendium_items')->nullOnDelete();
            $table->float('x'); // centre position as a percentage of image width (0–100)
            $table->float('y'); // …and height
            $table->float('size')->default(1); // diameter in grid squares
            $table->string('label')->nullable();
            $table->string('color')->nullable(); // hex, e.g. #d8a94a
            $table->unsignedInteger('hp')->nullable();
            $table->unsignedInteger('max_hp')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_tokens');
    }
};
