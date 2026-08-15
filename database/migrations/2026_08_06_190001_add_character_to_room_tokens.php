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
            // A token dropped from the party roster points back at its Character.
            $table->foreignId('character_id')->nullable()->after('compendium_item_id')
                ->index()->constrained('characters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('room_tokens', function (Blueprint $table) {
            $table->dropForeign(['character_id']);
            $table->dropColumn('character_id');
        });
    }
};
