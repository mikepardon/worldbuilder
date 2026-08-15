<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // The D&D Beyond campaign chosen on the keys page, used to pull content shared with the GM.
            $table->string('ddb_campaign_id')->nullable()->after('ddb_cobalt');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('ddb_campaign_id');
        });
    }
};
