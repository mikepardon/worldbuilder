<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compendium_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('compendium_sources')->cascadeOnDelete();
            $table->string('status')->default('running'); // running | complete | failed
            $table->unsignedInteger('added')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compendium_import_runs');
    }
};
