<?php

declare(strict_types=1);

use App\Models\Session;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which player accounts attended a session — a link between a {@see Session} and the campaign
 * members (users) who were present.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->index()->constrained('campaign_sessions')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['session_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_attendees');
    }
};
