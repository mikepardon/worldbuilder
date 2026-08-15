<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the party's forward-looking action items to a recap — the bullet-point list of what they decided or
 * intend to do next, in the spirit of meeting-notes action items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recaps', function (Blueprint $table) {
            $table->jsonb('next_steps')->nullable()->after('outline');
        });
    }

    public function down(): void
    {
        Schema::table('recaps', function (Blueprint $table) {
            $table->dropColumn('next_steps');
        });
    }
};
