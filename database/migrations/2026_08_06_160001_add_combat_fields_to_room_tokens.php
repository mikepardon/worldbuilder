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
            // Portrait shown inside the token circle (monster art, a DDB avatar, or an upload).
            $table->foreignId('image_media_id')->nullable()->after('compendium_item_id')
                ->index()->constrained('media')->nullOnDelete();
            // What the token represents; drives the click-through detail (statblock vs player notes).
            $table->string('kind')->default('custom')->after('color'); // player | monster | custom
            // The D&D Beyond character this token was imported from, if any.
            $table->string('ddb_character_id')->nullable()->after('kind');
            $table->unsignedInteger('ac')->nullable()->after('max_hp');
            // Player notes / a short DDB profile summary. Surfaced to the GM and the token's owner only.
            $table->text('notes')->nullable()->after('ac');
            // Whether the token is on the initiative tracker (a token can sit on the map untracked).
            $table->boolean('in_tracker')->default(false)->after('initiative');
        });

        Schema::table('rooms', function (Blueprint $table) {
            // Compendium monster ids the GM lets players add themselves (pets, wildshape, summons).
            $table->jsonb('player_monster_ids')->nullable()->after('fog');
        });
    }

    public function down(): void
    {
        Schema::table('room_tokens', function (Blueprint $table) {
            $table->dropForeign(['image_media_id']);
            $table->dropColumn(['image_media_id', 'kind', 'ddb_character_id', 'ac', 'notes', 'in_tracker']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('player_monster_ids');
        });
    }
};
