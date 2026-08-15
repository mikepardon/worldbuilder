<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global, admin-curated SRD library. Worlds copy from here into campaign_compendium_items.
        Schema::create('compendium_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained('compendium_sources')->nullOnDelete();
            $table->string('item_type');
            $table->string('slug');
            $table->string('name');
            $table->text('summary')->nullable();
            $table->longText('document')->nullable();
            $table->jsonb('fields')->nullable();     // structured (e.g. monster stat block)
            $table->jsonb('data')->nullable();       // raw Open5e record
            $table->string('fingerprint', 64)->nullable(); // sha256 of data, for change detection
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['item_type', 'slug']);
            $table->index('item_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compendium_items');
    }
};
