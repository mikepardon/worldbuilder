<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An audio recording of a play session, processed in the background: transcribed (via a Transcriber
 * service), then structured by the AI into a recap, a scene outline and an entity list. The session
 * editor polls the record's status the way the D&D Beyond import page polls its import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('campaign_sessions')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('detail_level')->default('comprehensive'); // brief | comprehensive
            $table->string('status')->default('queued'); // queued | transcribing | structuring | done | failed
            $table->longText('transcript')->nullable();
            $table->longText('recap')->nullable();
            $table->jsonb('outline')->nullable();  // [{ title, detail }]
            $table->jsonb('entities')->nullable(); // [{ name, type, description }]
            $table->string('error')->nullable();
            $table->timestamps();
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_recordings');
    }
};
