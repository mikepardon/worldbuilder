<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('image_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_private')->default(true); // GM-only until revealed to players
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['campaign_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maps');
    }
};
