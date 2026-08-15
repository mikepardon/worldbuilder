<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roll_tables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->index()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // The die this table rolls on (its highest range value): 4, 6, 20, 100, …
            $table->unsignedInteger('die')->default(20);
            // Rows: [{ min, max, result, detail, link }] where link is another roll_tables id or null.
            $table->jsonb('rows');
            $table->boolean('is_private')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roll_tables');
    }
};
