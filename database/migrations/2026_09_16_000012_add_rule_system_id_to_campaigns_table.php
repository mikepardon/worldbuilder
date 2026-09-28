<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rule system in play for a campaign, if any. Players' characters in the campaign build against
 * it. Nulled (not blocked) if the system is later removed, so the campaign simply loses its system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('rule_system_id')->nullable()->after('game_system')
                ->constrained('rule_systems')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rule_system_id');
        });
    }
};
