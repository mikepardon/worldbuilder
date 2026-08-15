<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->boolean('is_featured')->default(false)->after('is_private');
            $table->boolean('hide_from_search')->default(false)->after('is_featured');
            // How the reader hero renders: default (by section), always show, or hide.
            $table->string('cover_mode')->default('default')->after('hide_from_search');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['is_featured', 'hide_from_search', 'cover_mode']);
        });
    }
};
