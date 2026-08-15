<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('world_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_id')->index()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('type')->default('text');
            $table->jsonb('options')->nullable();
            $table->jsonb('ref_kinds')->nullable();
            $table->jsonb('kinds')->nullable();
            $table->boolean('required')->default(false);
            // visible=false against a key that also exists globally hides that default field for this world.
            $table->boolean('visible')->default(true);
            $table->string('help')->nullable();
            $table->string('placeholder')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['world_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('world_attributes');
    }
};
