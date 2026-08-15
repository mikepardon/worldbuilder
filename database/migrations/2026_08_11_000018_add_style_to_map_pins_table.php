<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_pins', function (Blueprint $table): void {
            // 'marker' (a labelled point) or 'token' (a portrait of the linked entry, like a VTT token).
            $table->string('style')->default('marker')->after('behavior');
        });
    }

    public function down(): void
    {
        Schema::table('map_pins', function (Blueprint $table): void {
            $table->dropColumn('style');
        });
    }
};
