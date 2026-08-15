<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_messages', function (Blueprint $table) {
            // Whisper recipient. Null = public. A private message is visible only to sender, recipient
            // and the GM (filtered server-side, never sent to other clients).
            $table->foreignId('to_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('room_messages', function (Blueprint $table) {
            $table->dropForeign(['to_user_id']);
            $table->dropColumn('to_user_id');
        });
    }
};
