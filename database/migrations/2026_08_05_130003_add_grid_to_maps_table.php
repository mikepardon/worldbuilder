<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->boolean('grid_visible')->default(false)->after('is_private');
            $table->unsignedInteger('grid_size')->default(20)->after('grid_visible'); // squares across the image
            $table->float('unit_size')->nullable()->after('grid_size');                // real distance per square (e.g. 5)
            $table->string('unit')->nullable()->after('unit_size');                    // e.g. "ft"
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->dropColumn(['grid_visible', 'grid_size', 'unit_size', 'unit']);
        });
    }
};
