<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compendium_items', function (Blueprint $table) {
            // Whether this library entry is offered to game masters when browsing to import.
            $table->boolean('visible')->default(true)->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('compendium_items', function (Blueprint $table) {
            $table->dropColumn('visible');
        });
    }
};
