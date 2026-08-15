<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Platform admins grant each world a number of AI generations; usage counts up to the limit.
            $table->unsignedInteger('ai_generation_limit')->default(0)->after('cover_media_id');
            $table->unsignedInteger('ai_generations_used')->default(0)->after('ai_generation_limit');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['ai_generation_limit', 'ai_generations_used']);
        });
    }
};
