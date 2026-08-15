<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GM-maintained facts and corrections for a session — e.g. "the players sometimes say 'Brian' but mean the
 * character Clayton". These are fed to the analysis so a re-run produces an accurate recap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recaps', function (Blueprint $table) {
            $table->jsonb('facts')->nullable()->after('next_steps');
        });
    }

    public function down(): void
    {
        Schema::table('recaps', function (Blueprint $table) {
            $table->dropColumn('facts');
        });
    }
};
