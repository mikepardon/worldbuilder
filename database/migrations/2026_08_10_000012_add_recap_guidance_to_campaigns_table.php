<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign-wide recap guidance fed to every session's analysis: standing facts and corrections (e.g. a
 * player's real name maps to their character) and output instructions (e.g. never mention dice rolls).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->jsonb('recap_facts')->nullable()->after('description');
            $table->jsonb('recap_instructions')->nullable()->after('recap_facts');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['recap_facts', 'recap_instructions']);
        });
    }
};
