<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription plan + AI-credit accounting on the user. Daily credits reset each day from the plan's
 * allowance; `ai_credit_balance` holds purchased top-ups that don't reset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('plan')->default('free')->after('is_admin');
            $table->integer('ai_credit_balance')->default(0)->after('plan');
            $table->integer('daily_ai_used')->default(0)->after('ai_credit_balance');
            $table->date('daily_ai_reset_on')->nullable()->after('daily_ai_used');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['plan', 'ai_credit_balance', 'daily_ai_used', 'daily_ai_reset_on']);
        });
    }
};
