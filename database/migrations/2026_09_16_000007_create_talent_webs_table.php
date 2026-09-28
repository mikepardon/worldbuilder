<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A talent web within a rule system — one canvas of nodes and edges. A system may own several (e.g. a
 * core web, a magic web, faction paths). layout holds canvas config (centre, ring radii) for the
 * builder's auto-arrange helper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_webs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_system_id')->constrained('rule_systems')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->json('layout')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->index('rule_system_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_webs');
    }
};
