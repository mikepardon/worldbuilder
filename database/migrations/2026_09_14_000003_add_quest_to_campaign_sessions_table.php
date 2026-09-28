<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The session's quest — the single problem the players face this night — shown above its key moments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_sessions', function (Blueprint $table) {
            $table->text('quest')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_sessions', function (Blueprint $table) {
            $table->dropColumn('quest');
        });
    }
};
