<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('world_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->index()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('name');
            // A reusable block set: { blocks: [ ... ] }, referenced from templates by a "reusable" block.
            $table->jsonb('layout');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('world_blocks');
    }
};
