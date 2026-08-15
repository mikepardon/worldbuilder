<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A personal D&D Beyond cobalt session token, set from the account menu. Worlds fall back to their
 * owner's personal key, so a GM configures it once. Encrypted at rest via the model cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('ddb_cobalt')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ddb_cobalt');
        });
    }
};
