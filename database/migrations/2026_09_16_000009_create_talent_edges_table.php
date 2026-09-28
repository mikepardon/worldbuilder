<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An undirected connection between two nodes on the same web. Allocation requires a node to touch one
 * already owned, so edges define the reachable paths through the web.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talent_web_id')->constrained('talent_webs')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('from_node_id')->constrained('talent_nodes')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('to_node_id')->constrained('talent_nodes')->cascadeOnUpdate()->cascadeOnDelete();
            $table->timestamps();

            $table->index('talent_web_id');
            $table->unique(['from_node_id', 'to_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_edges');
    }
};
