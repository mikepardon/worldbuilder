<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a character point at a race entry in its world's compendium, so the race's base attribute
 * modifiers and granted spells/feats fold into its sheet before any talent points are spent. The
 * free-text `race` column stays for D&D-Beyond-imported characters, which carry a race name only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('race_compendium_item_id')->nullable()->after('race')
                ->constrained('campaign_compendium_items')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('race_compendium_item_id');
        });
    }
};
