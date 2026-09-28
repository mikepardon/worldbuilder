<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Narrative arcs within a campaign — a run of sessions telling one movement of the story. Sessions
 * point at an arc (see the companion migration adding campaign_sessions.arc_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_arcs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('summary')->nullable();
            $table->string('status')->default('to_play');
            $table->integer('sort')->default(0);
            $table->timestamps();

            // Postgres doesn't index foreign keys automatically; the board queries arcs by campaign.
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_arcs');
    }
};
