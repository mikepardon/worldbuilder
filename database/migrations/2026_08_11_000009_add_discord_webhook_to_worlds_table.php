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
            // Encrypted at rest (it carries a secret token); nullable until the GM sets one.
            $table->text('discord_webhook')->nullable()->after('custom_domain_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('worlds', function (Blueprint $table): void {
            $table->dropColumn('discord_webhook');
        });
    }
};
