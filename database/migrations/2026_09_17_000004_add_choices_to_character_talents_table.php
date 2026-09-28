<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A node can now carry a nested AND/OR tree of effects where an ANY group is a player choice. `choices`
 * records which child a character picked at each ANY group (keyed by the group's stable id), so the
 * sheet can resolve their chosen path. `chosen_option` stays for the older single-choice nodes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_talents', function (Blueprint $table) {
            $table->json('choices')->nullable()->after('chosen_option');
        });
    }

    public function down(): void
    {
        Schema::table('character_talents', function (Blueprint $table) {
            $table->dropColumn('choices');
        });
    }
};
