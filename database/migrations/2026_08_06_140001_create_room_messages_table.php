<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->jsonb('roll')->nullable(); // {expr, total, detail} for /roll messages
            $table->timestamps();
            $table->index('room_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_messages');
    }
};
