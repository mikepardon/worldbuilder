<?php

declare(strict_types=1);

use App\Models\Room;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scenes let a room hold several maps and switch the active one. Each scene owns its map image, grid,
 * unit and fog; tokens/templates/drawings belong to a scene. Existing rooms become a single scene so
 * nothing is lost, and that scene is made active.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('room_scenes')) {
            Schema::create('room_scenes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('room_id')->constrained()->cascadeOnDelete();
                $table->string('name')->default('Scene');
                $table->foreignId('image_media_id')->nullable()->constrained('media')->nullOnDelete();
                $table->boolean('grid_visible')->default(true);
                $table->integer('grid_size')->default(20);
                $table->float('unit_size')->default(5);
                $table->string('unit', 12)->default('ft');
                $table->boolean('fog_enabled')->default(false);
                $table->jsonb('fog')->nullable();
                $table->integer('sort')->default(0);
                $table->timestamps();
                $table->index('room_id');
            });
        }

        if (! Schema::hasColumn('rooms', 'active_scene_id')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->foreignId('active_scene_id')->nullable()->after('id')->constrained('room_scenes')->nullOnDelete();
            });
        }
        foreach (['room_tokens', 'room_templates', 'room_drawings'] as $child) {
            if (! Schema::hasColumn($child, 'scene_id')) {
                Schema::table($child, function (Blueprint $table) {
                    $table->foreignId('scene_id')->nullable()->after('room_id')->constrained('room_scenes')->cascadeOnDelete();
                });
            }
        }

        // Backfill: one scene per existing room (without one yet), carrying its map/grid/fog, made active.
        Room::query()->whereNull('active_scene_id')->get()->each(function (Room $room): void {
            $scene = $room->scenes()->create([
                'name' => 'Scene 1',
                'image_media_id' => $room->image_media_id,
                'grid_visible' => $room->grid_visible ?? true,
                'grid_size' => $room->grid_size ?? 20,
                'unit_size' => $room->unit_size ?? 5,
                'unit' => $room->unit ?? 'ft',
                'fog_enabled' => $room->fog_enabled ?? false,
                'fog' => $room->fog,
            ]);
            $room->tokens()->update(['scene_id' => $scene->id]);
            $room->templates()->update(['scene_id' => $scene->id]);
            $room->drawings()->update(['scene_id' => $scene->id]);
            $room->forceFill(['active_scene_id' => $scene->id])->save();
        });
    }

    public function down(): void
    {
        Schema::table('room_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scene_id');
        });
        Schema::table('room_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scene_id');
        });
        Schema::table('room_drawings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scene_id');
        });
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_scene_id');
        });
        Schema::dropIfExists('room_scenes');
    }
};
