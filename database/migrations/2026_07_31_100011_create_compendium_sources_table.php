<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compendium_sources', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();        // open5e-monsters
            $table->string('name');                 // Monsters
            $table->string('provider')->default('open5e');
            $table->string('item_type');            // monster, spell, …
            $table->string('api_url');              // https://api.open5e.com/v1/monsters/?limit=100
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compendium_sources');
    }
};
