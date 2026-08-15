<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real-world scheduling events (when the group next plays), per campaign — distinct from the
        // in-world custom calendars.
        Schema::create('schedule_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->index()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('title');
            $table->dateTime('starts_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_events');
    }
};
