<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A build now runs as a chain of short jobs — outline, then one batch per run — so no single run is long
 * enough to trip the queue's retry_after or the worker's timeout. The plan and how far we've got through
 * it are persisted here so each run can pick up where the last left off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('world_builds', function (Blueprint $table) {
            $table->jsonb('outline')->nullable()->after('planned');   // the entities to build, from pass one
            $table->unsignedInteger('cursor')->default(0)->after('created'); // next outline index to write
        });
    }

    public function down(): void
    {
        Schema::table('world_builds', function (Blueprint $table) {
            $table->dropColumn(['outline', 'cursor']);
        });
    }
};
