<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TalentEdge;
use App\Models\TalentWeb;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Connections between two nodes on the same {@see TalentWeb}. Both ends must belong to the web, and a
 * pair can only be joined once (either direction).
 */
class TalentEdgeController extends Controller
{
    public function store(Request $request, TalentWeb $web)
    {
        $this->authorize('update', $web->ruleSystem);

        $data = $request->validate([
            'from_node_id' => ['required', 'integer', Rule::exists('talent_nodes', 'id')->where('talent_web_id', $web->id)],
            'to_node_id' => ['required', 'integer', 'different:from_node_id', Rule::exists('talent_nodes', 'id')->where('talent_web_id', $web->id)],
        ]);

        $from = (int) $data['from_node_id'];
        $to = (int) $data['to_node_id'];

        $exists = $web->edges()
            ->where(fn ($query) => $query
                ->where(fn ($pair) => $pair->where('from_node_id', $from)->where('to_node_id', $to))
                ->orWhere(fn ($pair) => $pair->where('from_node_id', $to)->where('to_node_id', $from)))
            ->exists();

        if (! $exists) {
            $web->edges()->create(['from_node_id' => $from, 'to_node_id' => $to]);
        }

        return back();
    }

    public function destroy(TalentEdge $edge)
    {
        $this->authorize('update', $edge->web->ruleSystem);

        $edge->delete();

        return back();
    }
}
