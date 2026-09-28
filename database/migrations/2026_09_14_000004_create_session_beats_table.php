<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Key moments within a session — the ordered beats the GM walks through: the start, each event, the
 * main encounter, links between them, and the cliffhanger. Deleted with their session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_beats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('campaign_sessions')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('kind')->default('event');
            $table->text('body')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_beats');
    }
};
