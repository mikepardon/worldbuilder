<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promotes a recap's extracted entities from an inline JSON blob to their own rows, so each can carry
 * reconciliation state: a link to a real world entry (a Document or a Compendium item) and a status. The
 * legacy `recaps.entities` JSON is backfilled into the new table and then dropped, which makes this
 * migration irreversible (the column's data cannot be restored), so there is no down method.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recap_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recap_id')->index()->constrained('recaps')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('name');
            $table->string('type'); // npc | location | faction | item | monster | spell
            $table->text('description')->nullable();
            $table->string('status')->default('unmatched'); // unmatched | linked | created | dismissed
            $table->foreignId('linked_document_id')->nullable()->index()
                ->constrained('documents')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('linked_compendium_item_id')->nullable()->index()
                ->constrained('campaign_compendium_items')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();
        });

        // Backfill the new rows from the legacy JSON, as unmatched (reconciliation is a GM action).
        foreach (DB::table('recaps')->select('id', 'entities')->get() as $recap) {
            $entities = json_decode((string) $recap->entities, true);
            if (! is_array($entities)) {
                continue;
            }

            $rows = [];
            foreach ($entities as $entity) {
                if (! is_array($entity) || blank($entity['name'] ?? null)) {
                    continue;
                }
                $rows[] = [
                    'recap_id' => $recap->id,
                    'name' => (string) $entity['name'],
                    'type' => (string) ($entity['type'] ?? 'npc'),
                    'description' => (string) ($entity['description'] ?? ''),
                    'status' => 'unmatched',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::table('recap_entities')->insert($rows);
            }
        }

        Schema::table('recaps', function (Blueprint $table) {
            $table->dropColumn('entities');
        });
    }
};
