<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recaps', function (Blueprint $table): void {
            // When set, the GM has published this recap to players. Null while awaiting GM review
            // (only relevant when the world requires review rather than auto-publishing).
            $table->timestamp('published_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('recaps', function (Blueprint $table): void {
            $table->dropColumn('published_at');
        });
    }
};
