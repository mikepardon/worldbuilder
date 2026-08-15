<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->index()->constrained()->cascadeOnDelete();
            // The player who owns this character; null for a GM-kept NPC or an unassigned import.
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('image_media_id')->nullable()->index()->constrained('media')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('ddb_character_id')->nullable();
            $table->string('ddb_url')->nullable();
            $table->unsignedInteger('level')->nullable();
            $table->string('class')->nullable();
            $table->string('race')->nullable();
            $table->unsignedInteger('ac')->nullable();
            $table->unsignedInteger('hp')->nullable();
            $table->unsignedInteger('max_hp')->nullable();
            $table->jsonb('stats')->nullable(); // ability scores {str,dex,con,int,wis,cha}
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
