<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('world_attributes', function (Blueprint $table): void {
            // When true the field holds several values (e.g. a "Staff" field with many NPC references).
            $table->boolean('multiple')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('world_attributes', function (Blueprint $table): void {
            $table->dropColumn('multiple');
        });
    }
};
