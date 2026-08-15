<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An entry template can be the default for its kind: entries of that kind with no template of their own
 * are styled by it automatically. At most one default per kind (enforced in the controller).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('world_templates', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('section');
        });
    }

    public function down(): void
    {
        Schema::table('world_templates', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }
};
