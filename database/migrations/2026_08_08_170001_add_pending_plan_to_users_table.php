<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scheduled downgrade the user has chosen but which only takes effect at the end of their paid
 * period. Recorded here purely so the billing page can clearly say "you'll move to X on <date>".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_plan')->nullable()->after('stripe_subscription_id');
            $table->timestamp('pending_plan_at')->nullable()->after('pending_plan');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pending_plan', 'pending_plan_at']);
        });
    }
};
