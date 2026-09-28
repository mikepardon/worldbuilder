<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The node types of a rule system — the GM's equivalent of Minor/Notable/Keystone. Each carries a
 * default point cost and its rendered shape/glyph/size, so custom kinds look distinct on the web.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_node_kinds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_system_id')->constrained('rule_systems')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->integer('default_cost')->default(1);
            $table->string('shape')->default('circle');
            $table->string('glyph')->nullable();
            $table->integer('size')->default(34);
            $table->string('colour')->nullable();
            // Optional cap on how many nodes of this kind a character may hold (e.g. keystones); null = no cap.
            $table->integer('max_per_character')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->unique(['rule_system_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_node_kinds');
    }
};
