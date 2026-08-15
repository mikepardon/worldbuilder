<?php

use App\Models\Campaign;
use App\Models\Document;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('code');
        });

        // Backfill world slugs: "{name}-{owner-username}", disambiguated with -2, -3, …
        Campaign::with('owner')->get()->each(function (Campaign $campaign) {
            $base = trim(Str::slug($campaign->name).'-'.Str::slug((string) $campaign->owner?->name, ''), '-') ?: 'world';
            $slug = $base;
            $n = 2;
            while (Campaign::where('slug', $slug)->where('id', '!=', $campaign->id)->exists()) {
                $slug = $base.'-'.$n++;
            }
            $campaign->forceFill(['slug' => $slug])->saveQuietly();
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->unique('slug');
        });

        // Rewrite document slugs into clean, per-world+kind slugs (drops the old random suffix).
        Document::query()->orderBy('id')->get()->each(function (Document $document) {
            $slug = Document::uniqueSlug($document->campaign_id, $document->kind, $document->title, $document->id);
            $document->forceFill(['slug' => $slug])->saveQuietly();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
