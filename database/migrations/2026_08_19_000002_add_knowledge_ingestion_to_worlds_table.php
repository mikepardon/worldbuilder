<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform admins grant a world access to the "Add knowledge" ingestion tool, exactly like the D&D Beyond
 * importer (`ddb_enabled`). Off by default; toggled from the admin Worlds page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worlds', function (Blueprint $table) {
            $table->boolean('knowledge_ingestion_enabled')->default(false)->after('ddb_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('worlds', function (Blueprint $table) {
            $table->dropColumn('knowledge_ingestion_enabled');
        });
    }
};
