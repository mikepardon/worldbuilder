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
            $table->string('concentrating_on')->nullable()->after('exhaustion');
        });
    }

    public function down(): void
    {
        Schema::table('room_tokens', function (Blueprint $table) {
            $table->dropColumn('concentrating_on');
        });
    }
};
