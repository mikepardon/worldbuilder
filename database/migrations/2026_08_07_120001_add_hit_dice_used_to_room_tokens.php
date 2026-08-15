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
            // Hit dice spent this session, keyed by die size (e.g. {"6": 2, "10": 1}).
            $table->jsonb('hit_dice_used')->nullable()->after('spell_slots_used');
        });
    }

    public function down(): void
    {
        Schema::table('room_tokens', function (Blueprint $table) {
            $table->dropColumn('hit_dice_used');
        });
    }
};
