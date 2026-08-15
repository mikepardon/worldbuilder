<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            // A campaign's own Discord channel webhook (encrypted at rest). Falls back to the world's
            // webhook when blank, so a campaign can post to its own server or inherit the world's.
            $table->text('discord_webhook')->nullable()->after('game_system');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('discord_webhook');
        });
    }
};
