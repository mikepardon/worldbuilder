<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pooled resources of a rule system — the GM's equivalent of Life/Mana. Node effects add flat or
 * percentage bonuses to these by key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_system_id')->constrained('rule_systems')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->text('description')->nullable();
            $table->integer('base_value')->default(0);
            $table->string('colour')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->unique(['rule_system_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_resources');
    }
};
