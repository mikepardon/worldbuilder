<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worlds', function (Blueprint $table): void {
            // { kind: [field key, field key, …] } — the GM's display order for each kind's quick-facts.
            $table->jsonb('field_order')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('worlds', function (Blueprint $table): void {
            $table->dropColumn('field_order');
        });
    }
};
