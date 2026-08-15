<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // Dedup key for externally-sourced images (e.g. "ddb-monster-17100"). A shared DDB image is
            // stored once (campaign_id null) and reused by every world that imports the same creature.
            // Multiple NULLs are expected (ordinary uploads have no external key), so a plain unique
            // index — which keeps NULLs distinct — is correct here.
            $table->string('external_key')->nullable()->after('campaign_id');
            $table->unique('external_key');
        });

        // Shared images must outlive the GM who first imported them, so the uploader becomes optional
        // and detaches (rather than cascade-deleting the file) when that user is removed.
        Schema::table('media', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('media', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['external_key']);
            $table->dropColumn('external_key');
        });
        Schema::table('media', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
