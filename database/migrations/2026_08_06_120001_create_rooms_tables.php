<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('image_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('name');
            $table->string('code')->unique(); // shareable join code
            $table->boolean('grid_visible')->default(true);
            $table->unsignedInteger('grid_size')->default(20);
            $table->float('unit_size')->nullable();
            $table->string('unit')->nullable();
            $table->boolean('fog_enabled')->default(false);
            $table->jsonb('fog')->nullable();
            $table->unsignedInteger('round')->default(1);
            $table->timestamps();
        });

        Schema::create('room_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['room_id', 'user_id']);
        });

        Schema::create('room_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            // The player who owns this token (null = a GM-placed token). Owners move only their own.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('compendium_item_id')->nullable()->constrained('campaign_compendium_items')->nullOnDelete();
            $table->float('x');
            $table->float('y');
            $table->float('size')->default(1);
            $table->string('label')->nullable();
            $table->string('color')->nullable();
            $table->unsignedInteger('hp')->nullable();
            $table->unsignedInteger('max_hp')->nullable();
            $table->integer('initiative')->nullable();
            $table->timestamps();
        });

        // The token whose turn it is (added after room_tokens exists).
        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignId('active_token_id')->nullable()->after('round')->constrained('room_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropForeign(['active_token_id']);
            $table->dropColumn('active_token_id');
        });
        Schema::dropIfExists('room_tokens');
        Schema::dropIfExists('room_members');
        Schema::dropIfExists('rooms');
    }
};
