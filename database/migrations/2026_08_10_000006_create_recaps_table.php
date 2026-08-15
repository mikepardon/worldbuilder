<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Recap is the post-play analysis of a session, built from an uploaded recording — separate from the
 * session's own prep document. One per session (re-uploading replaces it). It holds the raw transcript
 * plus the AI analysis: recap variants (full/short/stylized), memorable moments, a scene outline, and the
 * entities that appeared. This supersedes the earlier `session_recordings` table, which is dropped; that
 * was a brand-new feature with no data worth preserving, so this transition is irreversible (no down).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('session_recordings');

        Schema::create('recaps', function (Blueprint $table) {
            $table->id();
            // One recap per session — a re-upload replaces the existing one.
            $table->foreignId('session_id')->unique()->constrained('campaign_sessions')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('detail_level')->default('comprehensive'); // brief | comprehensive
            $table->string('status')->default('queued'); // queued | transcribing | analyzing | done | failed
            $table->unsignedTinyInteger('rating')->nullable(); // GM's 1–5 star rating of the analysis
            $table->longText('transcript')->nullable();
            $table->longText('recap_full')->nullable();
            $table->longText('recap_short')->nullable();
            $table->longText('recap_stylized')->nullable();
            $table->jsonb('moments')->nullable();   // [{ type, description, context }]
            $table->jsonb('outline')->nullable();   // [{ title, detail }]
            $table->jsonb('entities')->nullable();  // [{ name, type, description }]
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }
};
