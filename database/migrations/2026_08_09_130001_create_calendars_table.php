<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom in-world calendars: a world can invent its own months, weekdays and years, and pin events
 * to specific dates. Weekdays are derived deterministically from a day's absolute position, so the
 * same date always falls on the same weekday across years.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_id')->constrained('worlds')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->jsonb('months');   // [{ name, days }]
            $table->jsonb('weekdays'); // [ "Sunday", … ] (empty = no weekday grid)
            $table->integer('current_year')->default(1);
            $table->integer('sort')->default(0);
            $table->timestamps();
            $table->unique(['world_id', 'slug']);
        });

        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained('calendars')->cascadeOnUpdate()->cascadeOnDelete();
            $table->integer('year');
            $table->integer('month'); // 0-based index into the calendar's months
            $table->integer('day');   // 1-based day within the month
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['calendar_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('calendars');
    }
};
