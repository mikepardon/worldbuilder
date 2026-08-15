<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks a background "populate this world from its seed" run: the AI reads the GM's pasted notes and
 * creates a batch of wiki entries. The dashboard polls this record for live progress, the same way
 * the D&D Beyond import polls compendium_imports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('world_builds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_id')->index()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('status')->default('queued'); // queued | running | completed | failed
            $table->string('message')->nullable();
            $table->unsignedInteger('planned')->default(0); // entries the AI intends to create
            $table->unsignedInteger('created')->default(0); // entries created so far
            $table->jsonb('counts')->nullable();             // per-kind tally of what was created
            $table->jsonb('log')->nullable();                // running progress lines
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('world_builds');
    }
};
