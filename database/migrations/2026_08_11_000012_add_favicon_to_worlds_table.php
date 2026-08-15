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
            $table->foreignId('favicon_media_id')->nullable()->after('banner_media_id')
                ->constrained('media')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('worlds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('favicon_media_id');
        });
    }
};
