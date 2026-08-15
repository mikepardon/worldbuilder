<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A paid-plan custom domain for a world's public reader. `custom_domain_verified_at` is set once the
 * domain's A record is confirmed to point at us, at which point the domain serves the world.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worlds', function (Blueprint $table) {
            $table->string('custom_domain')->nullable()->unique()->after('visibility');
            $table->timestamp('custom_domain_verified_at')->nullable()->after('custom_domain');
        });
    }

    public function down(): void
    {
        Schema::table('worlds', function (Blueprint $table) {
            $table->dropColumn(['custom_domain', 'custom_domain_verified_at']);
        });
    }
};
