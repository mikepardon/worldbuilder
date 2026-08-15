<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worlds', function (Blueprint $table): void {
            // Reader branding: a header logo and a hero banner, each pointing at a media item.
            $table->foreignId('logo_media_id')->nullable()->after('cover_media_id')
                ->constrained('media')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('banner_media_id')->nullable()->after('logo_media_id')
                ->constrained('media')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('worlds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('logo_media_id');
            $table->dropConstrainedForeignId('banner_media_id');
        });
    }
};
