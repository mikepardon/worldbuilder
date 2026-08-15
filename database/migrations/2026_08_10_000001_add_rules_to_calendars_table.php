<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Richer calendar rules: leap-year rules (extra days added to a month on a cycle, with optional
 * Gregorian-style exceptions) and moons (lunar cycles whose phase is shown per day). Intercalary
 * festival days are a per-month flag stored in the existing `months` JSON, so they need no column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendars', function (Blueprint $table) {
            // [{ name, month, add, every, offset, except_every, unless_every }]
            $table->jsonb('leap_rules')->nullable()->after('weekdays');
            // [{ name, cycle, offset, colour }]
            $table->jsonb('moons')->nullable()->after('leap_rules');
        });
    }

    public function down(): void
    {
        Schema::table('calendars', function (Blueprint $table) {
            $table->dropColumn(['leap_rules', 'moons']);
        });
    }
};
