<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data half of the World/Campaign split: give every world a "Main Campaign", repoint its rooms, players,
 * invites and characters at that campaign, and move its session documents into `campaign_sessions`.
 * Irreversible (session documents are destroyed), so there is no down().
 */
return new class extends Migration
{
    /** Tables whose `campaign_id` moves from the world to the new campaign layer. */
    private const CAMPAIGN_OWNED = ['rooms', 'characters', 'campaign_members', 'campaign_invites'];

    public function up(): void
    {
        // The old FKs point at `worlds` after the rename; drop them so values can be repointed.
        foreach (self::CAMPAIGN_OWNED as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['campaign_id']);
            });
        }

        foreach (DB::table('worlds')->get() as $world) {
            $campaignId = DB::table('campaigns')->insertGetId([
                'world_id' => $world->id,
                'name' => 'Main Campaign',
                'slug' => 'main',
                'code' => $world->code ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (self::CAMPAIGN_OWNED as $tableName) {
                DB::table($tableName)->where('campaign_id', $world->id)->update(['campaign_id' => $campaignId]);
            }

            // Session documents become campaign sessions.
            $sessions = DB::table('documents')
                ->where('world_id', $world->id)->where('kind', 'session')->orderBy('id')->get();
            foreach ($sessions->values() as $index => $document) {
                DB::table('campaign_sessions')->insert([
                    'campaign_id' => $campaignId,
                    'title' => $document->title,
                    'slug' => $document->slug,
                    'summary' => $document->summary,
                    'body' => $document->content,
                    'sort' => $index,
                    'is_private' => (bool) $document->is_private,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('documents')->where('world_id', $world->id)->where('kind', 'session')->delete();
        }

        // Re-point the FKs at the new campaigns table.
        foreach (self::CAMPAIGN_OWNED as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            });
        }
    }
};
