<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a talent node point at a compendium spell or feat, so a node can grant an existing library entry
 * (and show its effects on click) rather than restating it. Only meaningful for a world's own systems —
 * templates have no world and so no compendium to draw from. Nulls out if the linked entry is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('talent_nodes', function (Blueprint $table) {
            $table->foreignId('compendium_item_id')->nullable()->after('kind')
                ->constrained('campaign_compendium_items')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('talent_nodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compendium_item_id');
        });
    }
};
