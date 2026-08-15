<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A compendium import now runs as a chain of short queued jobs rather than one long synchronous request.
 * The cursor records how far through the source the run has got, so each job can resume where the last
 * left off (an opaque page URL for Open5e, a numeric offset for dnd5eapi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compendium_import_runs', function (Blueprint $table) {
            $table->string('cursor')->nullable()->after('unchanged');
        });
    }

    public function down(): void
    {
        Schema::table('compendium_import_runs', function (Blueprint $table) {
            $table->dropColumn('cursor');
        });
    }
};
