<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SessionStatus;
use App\Models\Arc;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Narrative arcs within a {@see Campaign} — the top lane of the campaign board. Managed by the
 * campaign's GM. Sessions are placed into arcs from {@see SessionController::organise()}.
 */
class ArcController extends Controller
{
    public function store(Request $request, Campaign $campaign)
    {
        $this->authorize('manage', $campaign);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::enum(SessionStatus::class)],
        ]);

        $campaign->arcs()->create([
            'title' => $data['title'],
            'summary' => $data['summary'] ?? null,
            'status' => $data['status'] ?? SessionStatus::ToPlay->value,
            'sort' => (int) $campaign->arcs()->max('sort') + 1,
        ]);

        return back();
    }

    public function update(Request $request, Arc $arc)
    {
        $this->authorize('manage', $arc->campaign);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::enum(SessionStatus::class)],
        ]);

        $arc->update($data);

        return back();
    }

    public function destroy(Arc $arc)
    {
        $this->authorize('manage', $arc->campaign);

        // Un-group the arc's sessions, then remove it. Done explicitly so it holds on SQLite too, where
        // the arc_id foreign key (which would null the sessions on delete) isn't present.
        DB::transaction(function () use ($arc) {
            $arc->sessions()->update(['arc_id' => null]);
            $arc->delete();
        });

        return back();
    }

    public function reorder(Request $request, Campaign $campaign)
    {
        $this->authorize('manage', $campaign);

        $data = $request->validate([
            'ids' => ['present', 'array'],
            'ids.*' => ['integer'],
        ]);

        $ownIds = $campaign->arcs()->pluck('id')->all();
        $ordered = array_values(array_intersect(array_map('intval', $data['ids']), $ownIds));

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $index => $id) {
                Arc::where('id', $id)->update(['sort' => $index]);
            }
        });

        return back();
    }
}
