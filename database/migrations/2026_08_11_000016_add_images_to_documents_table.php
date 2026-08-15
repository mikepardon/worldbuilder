<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            // Per-entry imagery: a card thumbnail (listings) and a hero banner (the reader page).
            $table->foreignId('card_media_id')->nullable()->after('slug')
                ->constrained('media')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('banner_media_id')->nullable()->after('card_media_id')
                ->constrained('media')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('card_media_id');
            $table->dropConstrainedForeignId('banner_media_id');
        });
    }
};
