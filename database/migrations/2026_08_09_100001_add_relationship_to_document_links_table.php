<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A typed relationship on each connection (e.g. located_in, member_of), so the connections graph can
 * show what a link means and read it in both directions. `label` stays as an optional custom phrase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_links', function (Blueprint $table) {
            $table->string('relationship')->nullable()->after('label');
        });

        // Backfill existing links: wiki-links are "mentions"; hand-added links default to "related_to".
        DB::table('document_links')->where('source', 'wikilink')->update(['relationship' => 'mentions']);
        DB::table('document_links')->where('source', '!=', 'wikilink')->update(['relationship' => 'related_to']);
    }

    public function down(): void
    {
        Schema::table('document_links', function (Blueprint $table) {
            $table->dropColumn('relationship');
        });
    }
};
