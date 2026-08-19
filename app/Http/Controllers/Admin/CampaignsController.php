<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\World;
use App\Support\AiModels;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CampaignsController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Worlds', [
            'availableModels' => AiModels::available(),
            'defaultModel' => AiModels::default(),
            'worlds' => World::with('owner:id,email')->withCount('documents')->latest('updated_at')->get()
                ->map(fn (World $world) => [
                    'id' => $world->id,
                    'name' => $world->name,
                    'code' => $world->code, 'slug' => $world->slug,
                    'visibility' => $world->visibility,
                    'owner' => $world->owner?->email,
                    'documents_count' => $world->documents_count,
                    'public_url' => $world->isPublic() ? url("/w/{$world->slug}") : null,
                    'ai_generation_limit' => $world->ai_generation_limit,
                    'ai_generations_used' => $world->ai_generations_used,
                    'ai_model' => $world->ai_model,
                    'ddb_enabled' => $world->ddb_enabled,
                    'knowledge_ingestion_enabled' => $world->knowledge_ingestion_enabled,
                ]),
        ]);
    }

    /** Set (or clear) a world's AI model override. Empty means "use the global default". */
    public function aiModel(Request $request, World $world)
    {
        $data = $request->validate([
            'ai_model' => ['nullable', 'string', 'in:'.implode(',', AiModels::available())],
        ]);

        $world->update(['ai_model' => $data['ai_model'] ?? null]);

        return back()->with('success', "AI model updated for \"{$world->name}\".");
    }

    /** Set a world's AI generation allowance. */
    public function aiBudget(Request $request, World $world)
    {
        $data = $request->validate([
            'ai_generation_limit' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        $world->update(['ai_generation_limit' => $data['ai_generation_limit']]);

        return back()->with('success', "AI budget for \"{$world->name}\" set to {$data['ai_generation_limit']} generations.");
    }

    /** Grant or revoke a world's access to the D&D Beyond importer. */
    public function ddbAccess(Request $request, World $world)
    {
        $data = $request->validate(['ddb_enabled' => ['required', 'boolean']]);

        $world->update(['ddb_enabled' => $data['ddb_enabled']]);

        return back()->with('success', 'D&D Beyond import '.($data['ddb_enabled'] ? 'enabled' : 'disabled')." for \"{$world->name}\".");
    }

    /** Grant or revoke a world's access to the "Add knowledge" ingestion tool. */
    public function knowledgeAccess(Request $request, World $world)
    {
        $data = $request->validate(['knowledge_ingestion_enabled' => ['required', 'boolean']]);

        $world->update(['knowledge_ingestion_enabled' => $data['knowledge_ingestion_enabled']]);

        return back()->with('success', 'Knowledge ingestion '.($data['knowledge_ingestion_enabled'] ? 'enabled' : 'disabled')." for \"{$world->name}\".");
    }
}
