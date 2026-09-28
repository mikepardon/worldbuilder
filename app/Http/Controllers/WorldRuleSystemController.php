<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\RuleSystem;
use App\Models\World;
use App\Services\RuleSystemCloner;
use App\Support\DemoRuleSystem;
use App\Support\WorldNav;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * A world's own rule systems: the GM can start one from scratch, from the built-in starter, or clone a
 * global template to edit. Authoring uses the shared builder ({@see RuleSystemController}); this
 * controller owns the world-level list, creation, cloning, deletion, and enabling one on a campaign.
 */
class WorldRuleSystemController extends Controller
{
    public function index(World $world)
    {
        $this->authorize('editContent', $world);

        $systems = $world->ruleSystems()->withCount(['webs', 'stats', 'skills'])->orderBy('name')->get()
            ->map(fn (RuleSystem $system) => [
                'id' => $system->id,
                'name' => $system->name,
                'description' => $system->description,
                'webs_count' => $system->webs_count,
                'stats_count' => $system->stats_count,
                'skills_count' => $system->skills_count,
                'updated_at' => $system->updated_at?->toFormattedDateString(),
            ]);

        $templates = RuleSystem::where('is_template', true)->orderBy('name')->get()
            ->map(fn (RuleSystem $system) => [
                'id' => $system->id,
                'name' => $system->name,
                'description' => $system->description,
            ]);

        $campaigns = $world->campaigns()->orderBy('name')->get()
            ->map(fn (Campaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'rule_system_id' => $campaign->rule_system_id,
            ]);

        return Inertia::render('Worlds/RuleSystems/Index', [
            'world' => WorldNav::for($world),
            'systems' => $systems,
            'templates' => $templates,
            'campaigns' => $campaigns,
        ]);
    }

    public function store(Request $request, World $world)
    {
        $this->authorize('editContent', $world);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'starter' => ['sometimes', 'boolean'],
        ]);

        $system = $world->ruleSystems()->create([
            'user_id' => $request->user()->id,
            'is_template' => false,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'settings' => DemoRuleSystem::defaultSettings(),
        ]);

        if ($data['starter'] ?? false) {
            (new DemoRuleSystem)->populate($system);
        }

        return redirect()->route('rule-systems.build', $system);
    }

    public function clone(Request $request, World $world, RuleSystem $template, RuleSystemCloner $cloner)
    {
        $this->authorize('editContent', $world);
        abort_unless($template->is_template, 404);

        $copy = $cloner->cloneInto($template, $world, $request->user()->id);

        return redirect()->route('rule-systems.build', $copy);
    }

    public function assign(Request $request, Campaign $campaign)
    {
        $this->authorize('manage', $campaign);

        $data = $request->validate([
            'rule_system_id' => [
                'nullable',
                Rule::exists('rule_systems', 'id')->where('world_id', $campaign->world_id),
            ],
        ]);

        $campaign->update(['rule_system_id' => $data['rule_system_id'] ?? null]);

        return back();
    }

    public function destroy(RuleSystem $ruleSystem)
    {
        $this->authorize('delete', $ruleSystem);

        $world = $ruleSystem->world_id;
        $ruleSystem->delete();

        return redirect()->route('rule-systems.index', $world);
    }
}
