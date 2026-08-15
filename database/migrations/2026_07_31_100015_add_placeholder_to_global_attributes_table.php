<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('global_attributes', function (Blueprint $table) {
            $table->string('placeholder')->nullable()->after('help');
        });
    }

    public function down(): void
    {
        Schema::table('global_attributes', function (Blueprint $table) {
            $table->dropColumn('placeholder');
        });
    }
};
