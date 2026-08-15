<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_compendium_items', function (Blueprint $table) {
            // Where the entry actually came from (ddb | critterdb | open5e | dnd5eapi), for display.
            // Distinct from `provider`, which encodes edit semantics (imported = locked, custom = editable).
            // Null means hand-authored in this world.
            $table->string('origin')->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_compendium_items', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
