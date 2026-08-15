<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-wide custom-field schema. Mirrors campaign_attributes but curated by admins and
        // (when visible) offered to every world.
        Schema::create('global_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('type')->default('text'); // text, longtext, number, boolean, date, select, url, reference
            $table->jsonb('options')->nullable();
            $table->jsonb('ref_kinds')->nullable();
            $table->jsonb('kinds')->nullable();        // which doc kinds this applies to
            $table->boolean('required')->default(false);
            $table->boolean('visible')->default(true); // pushed to every world when true
            $table->string('help')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_attributes');
    }
};
