<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the AI model an admin-managed setting rather than an env var. `ai_settings` holds the global
 * default model and the assistant's product name; a world may override the model in `worlds.ai_model`
 * (null = use the global default). The model is never exposed to end users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worlds', function (Blueprint $table) {
            $table->string('ai_model')->nullable()->after('ai_generations_used');
        });

        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('default_model')->default('claude-sonnet-4-6');
            $table->string('assistant_name')->default('Muse');
            $table->timestamps();
        });

        DB::table('ai_settings')->insert([
            'default_model' => config('ai.default_model', 'claude-sonnet-4-6'),
            'assistant_name' => config('ai.name', 'Muse'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
        Schema::table('worlds', function (Blueprint $table) {
            $table->dropColumn('ai_model');
        });
    }
};
