<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_event_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_event_id')->index()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('status'); // attending | tentative | declined (validated in the app layer)
            $table->text('note')->nullable();
            $table->timestamps();

            // One RSVP per player per event; updated in place when they change their mind.
            $table->unique(['schedule_event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_event_responses');
    }
};
