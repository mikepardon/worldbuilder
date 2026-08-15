<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A secret token that, when set, exposes a read-only public page for the recap at /recap/{token} — a link
 * a GM can share with anyone, not just campaign members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recaps', function (Blueprint $table) {
            $table->string('share_token')->nullable()->unique()->after('rating');
        });
    }

    public function down(): void
    {
        Schema::table('recaps', function (Blueprint $table) {
            $table->dropColumn('share_token');
        });
    }
};
