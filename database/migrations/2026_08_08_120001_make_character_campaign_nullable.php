<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A character may be personal — owned by a player and not attached to any campaign yet (built or imported
 * from the dashboard, and attached to a campaign later). So `campaign_id` becomes nullable. Irreversible
 * (NULLs may be inserted), so there is no down().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->change();
        });
    }
};
