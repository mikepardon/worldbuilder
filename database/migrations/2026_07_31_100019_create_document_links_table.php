<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('to_document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('label')->nullable();          // e.g. "ally of", "located in"; null for a plain wiki-link
            $table->string('source')->default('manual');  // manual | wikilink
            $table->timestamps();

            $table->index('campaign_id');
            $table->index('from_document_id');
            $table->index('to_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_links');
    }
};
