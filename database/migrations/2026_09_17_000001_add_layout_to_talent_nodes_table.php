<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give nodes an explicit layout relationship so the builder can lay them out predictably: which node a
 * node hangs off (parent_node_id) and how it is arranged/moved (layout_role). Positions used to be pure
 * x/y with no notion of parent/child, so "children follow their parent", sibling auto-arrange and
 * Muse placement had nothing to key off. Existing rows default to a free-floating 'master' — every node
 * stays individually draggable — and nodes built from here on carry a real role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('talent_nodes', function (Blueprint $table) {
            // The node this one hangs off for auto-layout (a cluster centre for its ring, a branch node
            // for its children). Null for free-floating masters. Nulls out if the parent is deleted so a
            // branch is orphaned into free masters rather than cascade-deleted.
            $table->foreignId('parent_node_id')->nullable()->after('talent_web_id')
                ->constrained('talent_nodes')->cascadeOnUpdate()->nullOnDelete();
            // How the node is laid out and whether it can be dragged directly:
            // 'master' free/draggable hub, 'adjacent' draggable sibling, 'child' fanned branch node
            // (follows its parent), 'ring' cluster-ring node (follows its master). Kept a loose string
            // to match the existing loose `kind` column.
            $table->string('layout_role')->default('master')->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('talent_nodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_node_id');
            $table->dropColumn('layout_role');
        });
    }
};
