<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Templates can now target more than a single entry kind: an "entry" template styles a document (the
 * existing behaviour), a "home" template designs the reader's home page (one per world), and an
 * "archive" template designs a section's listing page. `section` records which section an archive
 * template applies to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('world_templates', function (Blueprint $table) {
            $table->string('target')->default('entry')->after('kind'); // entry | home | archive
            $table->string('section')->nullable()->after('target');    // for archive templates
        });
    }

    public function down(): void
    {
        Schema::table('world_templates', function (Blueprint $table) {
            $table->dropColumn(['target', 'section']);
        });
    }
};
