<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_tokens', function (Blueprint $table) {
            $table->unsignedInteger('temp_hp')->default(0)->after('max_hp');
            $table->unsignedTinyInteger('death_success')->default(0)->after('temp_hp');
            $table->unsignedTinyInteger('death_fail')->default(0)->after('death_success');
            // Spell slots expended this session, keyed by level (e.g. {"1": 2, "3": 1}).
            $table->jsonb('spell_slots_used')->nullable()->after('death_fail');
        });
    }

    public function down(): void
    {
        Schema::table('room_tokens', function (Blueprint $table) {
            $table->dropColumn(['temp_hp', 'death_success', 'death_fail', 'spell_slots_used']);
        });
    }
};
