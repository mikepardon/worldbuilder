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
            $table->string('accent')->nullable()->after('cover_mode');
            $table->dateTime('publish_at')->nullable()->after('accent');
            $table->boolean('comments_enabled')->default(true)->after('publish_at');
            $table->boolean('show_toc')->default(false)->after('comments_enabled');
            $table->jsonb('related_ids')->nullable()->after('show_toc');
            $table->jsonb('slug_aliases')->nullable()->after('related_ids');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['accent', 'publish_at', 'comments_enabled', 'show_toc', 'related_ids', 'slug_aliases']);
        });
    }
};
