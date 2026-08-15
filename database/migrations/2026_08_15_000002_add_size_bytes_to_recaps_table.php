<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recaps', function (Blueprint $table) {
            // Size of the uploaded recording in S3, so recap audio counts toward the owner's storage quota.
            $table->unsignedBigInteger('size_bytes')->default(0)->after('duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('recaps', function (Blueprint $table) {
            $table->dropColumn('size_bytes');
        });
    }
};
