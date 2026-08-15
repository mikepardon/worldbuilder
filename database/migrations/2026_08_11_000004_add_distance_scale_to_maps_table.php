<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table): void {
            // The real-world width the whole map image spans, and the unit it's measured in — used to
            // convert on-screen distances into real distances when measuring (feet/yards/miles/km).
            $table->decimal('real_width', 12, 2)->nullable()->after('unit');
            $table->string('distance_unit')->nullable()->after('real_width');
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table): void {
            $table->dropColumn(['real_width', 'distance_unit']);
        });
    }
};
