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
            // A person token points at a world "People" entry (an npc document) rather than a monster.
            $table->foreignId('document_id')->nullable()->after('compendium_item_id')
                ->index()->constrained('documents')->nullOnDelete();
            // Active status effects (5e conditions), stored as a list of condition keys.
            $table->jsonb('conditions')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('room_tokens', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropColumn(['document_id', 'conditions']);
        });
    }
};
