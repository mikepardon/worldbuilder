<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RuleSystem;
use App\Support\DemoRuleSystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The global template library. Admins build starter rule systems here (via the shared builder) that
 * any GM can clone into their world. Access is gated by the admin route group (can:access-admin).
 */
class RuleSystemController extends Controller
{
    public function index()
    {
        $templates = RuleSystem::where('is_template', true)
            ->withCount(['webs', 'stats', 'skills'])
            ->orderBy('name')
            ->get()
            ->map(fn (RuleSystem $system) => [
                'id' => $system->id,
                'name' => $system->name,
                'slug' => $system->slug,
                'description' => $system->description,
                'webs_count' => $system->webs_count,
                'stats_count' => $system->stats_count,
                'skills_count' => $system->skills_count,
                'updated_at' => $system->updated_at?->toFormattedDateString(),
            ]);

        return Inertia::render('Admin/RuleSystems/Index', [
            'templates' => $templates,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'starter' => ['sometimes', 'boolean'],
        ]);

        $system = DB::transaction(function () use ($request, $data): RuleSystem {
            $system = RuleSystem::create([
                'world_id' => null,
                'user_id' => $request->user()->id,
                'is_template' => true,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'settings' => DemoRuleSystem::defaultSettings(),
            ]);

            if ($data['starter'] ?? false) {
                (new DemoRuleSystem)->populate($system);
            }

            return $system;
        });

        return redirect()->route('rule-systems.build', $system);
    }

    public function destroy(RuleSystem $ruleSystem)
    {
        abort_unless($ruleSystem->is_template, 404);

        $ruleSystem->delete();

        return redirect()->route('admin.rule-systems.index');
    }
}
