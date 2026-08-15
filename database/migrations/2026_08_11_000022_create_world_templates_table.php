<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('world_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->index()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind');
            // Layout options: { facts: sidebar|top|off, width: normal|wide }.
            $table->jsonb('layout');
            $table->timestamps();
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('template_id')->nullable()->after('cover_mode')
                ->constrained('world_templates')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('template_id');
        });
        Schema::dropIfExists('world_templates');
    }
};
