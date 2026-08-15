<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            // The location this map depicts ("this is the map of X") — powers the expand-to-article panel.
            $table->foreignId('document_id')->nullable()->after('image_media_id')->constrained('documents')->nullOnDelete();
        });

        Schema::table('map_pins', function (Blueprint $table) {
            // What clicking the marker does: info (popup) | travel (open its location's map) | article.
            $table->string('behavior')->default('info')->after('document_id');
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropColumn('document_id');
        });
        Schema::table('map_pins', function (Blueprint $table) {
            $table->dropColumn('behavior');
        });
    }
};
