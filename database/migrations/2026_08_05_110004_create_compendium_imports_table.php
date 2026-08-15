<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compendium_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('ddb');
            $table->string('status')->default('queued'); // queued | running | completed | failed
            $table->jsonb('types');                        // ['monster','item','spell']
            $table->boolean('homebrew')->default(true);
            $table->string('ddb_campaign_id')->nullable(); // shared DDB campaign snapshot
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('images')->default(0);  // images fetched/reused this run
            $table->jsonb('counts')->nullable();             // per-type imported counts
            $table->string('message')->nullable();          // current step, shown live
            $table->text('error')->nullable();
            $table->jsonb('log')->nullable();                // list of {at, line}
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compendium_imports');
    }
};
