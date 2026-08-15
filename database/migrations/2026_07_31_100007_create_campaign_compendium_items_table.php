<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_compendium_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_type'); // spell, monster, magicitem, weapon, armor, ...
            $table->string('slug');
            $table->string('name');
            $table->text('summary')->nullable();
            $table->longText('document')->nullable(); // markdown / statblock render
            $table->jsonb('fields')->nullable(); // structured fields incl. statblock "block"
            $table->jsonb('data')->nullable(); // preserved source (e.g. Open5e) payload
            $table->string('source_item_id')->nullable(); // provenance of an imported copy
            $table->string('provider')->default('custom'); // custom | imported
            $table->boolean('is_private')->default(false);
            $table->timestamps();
            $table->index(['campaign_id', 'item_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_compendium_items');
    }
};
