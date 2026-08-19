<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks a background "add knowledge to an existing world" run: the user pastes freeform notes, the AI
 * proposes a reviewable plan of changes (create/update, documents + compendium), and — once the user
 * approves a subset — a credit-gated apply pass writes them. Progress is polled like {@see WorldBuild}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('world_ingestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_id')->index()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            // planning → ready (plan awaiting review) → applying → paused (out of credits) → completed | failed
            $table->string('status')->default('planning');
            $table->text('source_text');
            $table->string('message')->nullable();
            $table->unsignedInteger('planned')->default(0);  // approved changes to apply
            $table->unsignedInteger('applied')->default(0);  // approved changes written so far
            $table->unsignedInteger('cursor')->default(0);   // position in the approved list during apply
            $table->jsonb('counts')->nullable();             // per-kind tally of what was applied
            $table->jsonb('log')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('world_ingestion_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_ingestion_id')->index()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('action');   // create | update
            $table->string('target');   // document | compendium
            $table->string('kind');     // npc/location/faction/item/lore/article | monster/spell/…
            $table->string('title');
            $table->text('rationale')->nullable();   // what this change adds/does (shown in the review list)
            $table->text('instruction')->nullable(); // guidance the apply pass uses to generate the content
            // Set for updates (the matched existing entry) and back-filled on a created entry after apply.
            $table->foreignId('document_id')->nullable()->index()
                ->constrained('documents')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('campaign_compendium_item_id')->nullable()->index()
                ->constrained('campaign_compendium_items')->cascadeOnUpdate()->nullOnDelete();
            $table->string('decision')->default('pending');  // pending | approved | rejected
            $table->string('status')->default('pending');    // pending | applied | skipped | failed
            $table->string('result')->nullable();            // short note on what happened when applied
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('world_ingestion_changes');
        Schema::dropIfExists('world_ingestions');
    }
};
