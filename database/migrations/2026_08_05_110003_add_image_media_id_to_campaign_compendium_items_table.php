<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_compendium_items', function (Blueprint $table) {
            // The image shown for this entry. Points at a shared DDB image by default; a per-world
            // override just repoints it at a world-owned upload — so the link itself is the world↔image
            // relation and no shared image is ever mutated.
            $table->foreignId('image_media_id')->nullable()->after('data')
                ->constrained('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_compendium_items', function (Blueprint $table) {
            $table->dropForeign(['image_media_id']);
            $table->dropColumn('image_media_id');
        });
    }
};
