<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structural half of the World/Campaign split: the old `campaigns` table becomes `worlds` (the setting),
 * a new `campaigns` table hangs off each world (a playthrough), and `campaign_sessions` holds a
 * campaign's sessions. World-owned tables have their `campaign_id` renamed to `world_id`. The data
 * backfill + campaign-owned FK repointing lives in the following migration.
 */
return new class extends Migration
{
    /** World-owned tables whose `campaign_id` FK is really a world reference. */
    private const WORLD_OWNED = [
        'documents', 'campaign_compendium_items', 'maps', 'campaign_attributes',
        'compendium_imports', 'media', 'document_links', 'user_notes',
    ];

    public function up(): void
    {
        Schema::rename('campaigns', 'worlds');

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('world_id')->constrained('worlds')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['world_id', 'slug']);
            $table->index('code');
        });

        // Named `campaign_sessions` to avoid colliding with Laravel's framework `sessions` table.
        Schema::create('campaign_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('summary')->nullable();
            $table->longText('body')->nullable();
            $table->date('held_on')->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('is_private')->default(false);
            $table->timestamps();
            $table->unique(['campaign_id', 'slug']);
        });

        // The renamed table's child FKs now point at `worlds` — rename the columns to match.
        foreach (self::WORLD_OWNED as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->renameColumn('campaign_id', 'world_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::WORLD_OWNED as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->renameColumn('world_id', 'campaign_id');
            });
        }

        Schema::dropIfExists('campaign_sessions');
        Schema::dropIfExists('campaigns');
        Schema::rename('worlds', 'campaigns');
    }
};
