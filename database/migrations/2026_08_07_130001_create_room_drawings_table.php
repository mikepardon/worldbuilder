<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_drawings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 16);          // freehand | line | rect | ellipse
            $table->jsonb('points');              // [{x,y}, …] as percentages of the map
            $table->string('color', 9)->default('#e0743c');
            $table->boolean('fill')->default(false);
            $table->timestamps();
            $table->index('room_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_drawings');
    }
};
