<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // A DDB-linked character we couldn't read (private / not shared) — flagged for the GM.
            $table->boolean('ddb_unreadable')->default(false)->after('ddb_url');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('ddb_unreadable');
        });
    }
};
