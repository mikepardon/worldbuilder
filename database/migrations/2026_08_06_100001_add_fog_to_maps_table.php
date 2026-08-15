<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->boolean('fog_enabled')->default(false)->after('unit');
            // Revealed grid cells as "col,row" strings; everything else is covered when fog is on.
            $table->jsonb('fog')->nullable()->after('fog_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->dropColumn(['fog_enabled', 'fog']);
        });
    }
};
