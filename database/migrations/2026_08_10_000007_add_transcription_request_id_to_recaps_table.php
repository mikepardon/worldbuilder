<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When transcription runs asynchronously, Deepgram returns a request id we store so an incoming callback
 * can be correlated back to the recap it belongs to (and traced in logs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recaps', function (Blueprint $table) {
            $table->string('transcription_request_id')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('recaps', function (Blueprint $table) {
            $table->dropColumn('transcription_request_id');
        });
    }
};
