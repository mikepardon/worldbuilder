<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A node on a talent web — a talent, stat bump, spell or ability a character can allocate. Positioned
 * freely by x/y (the builder can auto-arrange radially). effects holds structured modifiers; options
 * holds choose-one variants; config holds the rest (drawback, mana, rank, solo, bridge, enhancements).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talent_web_id')->constrained('talent_webs')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            // The rule_node_kinds.key this node is (kept loose so kinds may be renamed).
            $table->string('kind');
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            // Optional ring number for gating/auto-layout (the origin sits at ring 0).
            $table->integer('ring')->nullable();
            $table->integer('gate_level')->default(1);
            $table->integer('cost')->default(1);
            $table->text('description')->nullable();
            // Structured modifiers: [{type:'stat'|'resource'|'skill'|'derived', key, delta?, pct?, proficiency?}].
            $table->json('effects')->nullable();
            // Choose-one variants, each {key, name, desc, effects:[...]}.
            $table->json('options')->nullable();
            // Everything else: drawback, mana, rank, solo, bridge targets, glyph/colour overrides, enhancements.
            $table->json('config')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->index('talent_web_id');
            $table->unique(['talent_web_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_nodes');
    }
};
