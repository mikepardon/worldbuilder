<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-entry access control for the public reader: an optional password gate, and a secret share token
 * that grants direct access to a single entry (even a GM-only one) without making it public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->text('access_password')->nullable()->after('is_private');
            $table->string('share_token')->nullable()->unique()->after('access_password');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['access_password', 'share_token']);
        });
    }
};
