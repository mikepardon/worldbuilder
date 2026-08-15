<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // uploader / owner
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete(); // null = personal library
            $table->string('disk');                 // where it lives (public | s3)
            $table->string('path');                 // key within the disk
            $table->string('filename');             // original file name
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0); // bytes
            $table->string('alt')->nullable();      // caption / alt text
            $table->timestamps();

            $table->index(['user_id', 'campaign_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
