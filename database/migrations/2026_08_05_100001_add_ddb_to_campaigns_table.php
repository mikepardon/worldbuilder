<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Platform admins grant a world access to the D&D Beyond importer (off by default).
            $table->boolean('ddb_enabled')->default(false)->after('ai_generations_used');
            // The GM's D&D Beyond Cobalt session token, stored encrypted (model cast) so imports of
            // their owned content can be repeated without re-pasting it.
            $table->text('ddb_cobalt')->nullable()->after('ddb_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['ddb_enabled', 'ddb_cobalt']);
        });
    }
};
