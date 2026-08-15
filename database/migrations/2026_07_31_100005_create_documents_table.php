<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // author
            $table->string('title');
            $table->string('slug');
            $table->string('kind'); // location, npc, faction, timeline, item, rule, session, quest, lore, spell, statblock
            $table->longText('content')->nullable();
            $table->text('summary')->nullable();
            $table->jsonb('data')->nullable(); // structured attribute values
            $table->boolean('is_private')->default(false); // GM-only when true
            $table->timestamps();
            $table->index(['campaign_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
