<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_tokens', function (Blueprint $table) {
            $table->string('layer', 8)->default('token')->after('kind'); // token | gm
        });
        Schema::table('room_drawings', function (Blueprint $table) {
            $table->string('layer', 8)->default('token')->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('room_tokens', function (Blueprint $table) {
            $table->dropColumn('layer');
        });
        Schema::table('room_drawings', function (Blueprint $table) {
            $table->dropColumn('layer');
        });
    }
};
