<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RuleSystem;
use App\Models\TalentWeb;
use Illuminate\Http\Request;

/**
 * A talent web within a {@see RuleSystem}. Edited through the shared builder; access is decided by the
 * system's policy (admins for templates, co-authors for world systems).
 */
class TalentWebController extends Controller
{
    public function store(Request $request, RuleSystem $ruleSystem)
    {
        $this->authorize('update', $ruleSystem);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $ruleSystem->webs()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'sort' => (int) $ruleSystem->webs()->max('sort') + 1,
        ]);

        return back();
    }

    public function update(Request $request, TalentWeb $web)
    {
        $this->authorize('update', $web->ruleSystem);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $web->update($data);

        return back();
    }

    public function destroy(TalentWeb $web)
    {
        $this->authorize('update', $web->ruleSystem);

        $web->delete();

        return back();
    }
}
