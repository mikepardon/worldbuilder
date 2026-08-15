<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_pins', function (Blueprint $table): void {
            // A token may instead point at a compendium monster; its portrait comes from that item.
            $table->foreignId('compendium_item_id')->nullable()->after('document_id')
                ->constrained('campaign_compendium_items')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('map_pins', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('compendium_item_id');
        });
    }
};
