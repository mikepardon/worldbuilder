<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('type')->default('text'); // text, longtext, number, boolean, date, select, url, reference
            $table->jsonb('options')->nullable();
            $table->jsonb('ref_kinds')->nullable();
            $table->jsonb('kinds')->nullable(); // which doc kinds this applies to
            $table->boolean('required')->default(false);
            $table->string('help')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_attributes');
    }
};
