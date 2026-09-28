<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Session;
use App\Models\SessionBeat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Key moments within a {@see Session} — the ordered beats shown on the campaign board's session panel.
 * Managed by the campaign's GM.
 */
class BeatController extends Controller
{
    public function store(Request $request, Session $session)
    {
        $this->authorize('manage', $session->campaign);

        $data = $request->validate([
            'kind' => ['sometimes', Rule::in(SessionBeat::KINDS)],
            'body' => ['nullable', 'string', 'max:4000'],
        ]);

        $session->beats()->create([
            'kind' => $data['kind'] ?? 'event',
            'body' => $data['body'] ?? null,
            'sort' => (int) $session->beats()->max('sort') + 1,
        ]);

        return back();
    }

    public function update(Request $request, SessionBeat $beat)
    {
        $this->authorize('manage', $beat->session->campaign);

        $data = $request->validate([
            'kind' => ['sometimes', Rule::in(SessionBeat::KINDS)],
            'body' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);

        $beat->update($data);

        return back();
    }

    public function destroy(SessionBeat $beat)
    {
        $this->authorize('manage', $beat->session->campaign);

        $beat->delete();

        return back();
    }

    public function reorder(Request $request, Session $session)
    {
        $this->authorize('manage', $session->campaign);

        $data = $request->validate([
            'ids' => ['present', 'array'],
            'ids.*' => ['integer'],
        ]);

        $ownIds = $session->beats()->pluck('id')->all();
        $ordered = array_values(array_intersect(array_map('intval', $data['ids']), $ownIds));

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $index => $id) {
                SessionBeat::where('id', $id)->update(['sort' => $index]);
            }
        });

        return back();
    }
}
