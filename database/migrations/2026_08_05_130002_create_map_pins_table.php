<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('map_id')->constrained()->cascadeOnDelete();
            // A pin may link to a world entry, or just be a labelled marker.
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->float('x'); // position as a percentage of image width (0–100)
            $table->float('y'); // …and height
            $table->string('label')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_pins');
    }
};
