<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A reference field describes a connection; these phrases make it read naturally from either
        // end in the connections web — e.g. a location's "Owner" field reads "owned by" from the
        // location and "owner of" from the person.
        Schema::table('world_attributes', function (Blueprint $table): void {
            $table->string('link_label')->nullable()->after('ref_kinds');
            $table->string('inverse_label')->nullable()->after('link_label');
        });

        Schema::table('global_attributes', function (Blueprint $table): void {
            $table->string('link_label')->nullable()->after('ref_kinds');
            $table->string('inverse_label')->nullable()->after('link_label');
        });
    }

    public function down(): void
    {
        Schema::table('world_attributes', function (Blueprint $table): void {
            $table->dropColumn(['link_label', 'inverse_label']);
        });

        Schema::table('global_attributes', function (Blueprint $table): void {
            $table->dropColumn(['link_label', 'inverse_label']);
        });
    }
};
