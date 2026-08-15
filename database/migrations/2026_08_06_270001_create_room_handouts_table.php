<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_handouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('image_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
            $table->index('room_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_handouts');
    }
};
