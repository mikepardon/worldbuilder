<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 16);          // circle | cone | line | square
            $table->float('x');                  // origin, percent of map width
            $table->float('y');                  // origin, percent of map height
            $table->float('length');             // feet: radius (circle) / length (cone, line) / side (square)
            $table->float('angle')->default(0);  // degrees, aim direction for cone and line
            $table->string('color', 9)->default('#d8a94a');
            $table->timestamps();
            $table->index('room_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_templates');
    }
};
