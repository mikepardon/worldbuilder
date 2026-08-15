<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_compendium_items', function (Blueprint $table) {
            $table->jsonb('tags')->nullable()->after('is_private');
            // Imported items are read-only; the GM toggles this to include/exclude them for players.
            $table->boolean('is_active')->default(true)->after('tags');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_compendium_items', function (Blueprint $table) {
            $table->dropColumn(['tags', 'is_active']);
        });
    }
};
