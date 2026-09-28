<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The core stats of a rule system — the GM's equivalent of Strength/Dexterity, but named and sized
 * however they like. Referenced by key from node effects and skills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_system_id')->constrained('rule_systems')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('abbreviation')->nullable();
            $table->text('description')->nullable();
            $table->integer('default_value')->default(0);
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->unique(['rule_system_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_stats');
    }
};
