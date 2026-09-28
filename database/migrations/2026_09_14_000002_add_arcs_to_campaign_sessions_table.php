<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group sessions under a narrative arc and give each one a play status (played / playing / to_play).
 * The arc_id foreign key is added on drivers that can alter a table to add one — SQLite (used only in
 * tests) cannot, so there the column stands alone and the app enforces the relationship.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_sessions', function (Blueprint $table) {
            $table->foreignId('arc_id')->nullable()->after('campaign_id')->index();
            $table->string('status')->default('to_play')->after('sort');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('campaign_sessions', function (Blueprint $table) {
                // Deleting an arc un-groups its sessions rather than deleting them.
                $table->foreign('arc_id')->references('id')->on('campaign_arcs')
                    ->cascadeOnUpdate()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('campaign_sessions', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['arc_id']);
            }
            $table->dropColumn(['arc_id', 'status']);
        });
    }
};
