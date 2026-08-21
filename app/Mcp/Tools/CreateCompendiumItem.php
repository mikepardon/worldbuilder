<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\World;
use App\Support\Compendium;
use App\Support\Statblock;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new compendium entry in one of the user\'s worlds. The user must own or be an editor of the world.')]
class CreateCompendiumItem extends Tool
{
    public function handle(Request $request): Response
    {
        $user = $request->user();
        if ($user === null) {
            return Response::error('You must be authenticated to use this tool.');
        }

        $validated = $request->validate([
            'world_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:160'],
            'item_type' => ['required', 'in:'.implode(',', Compendium::keys())],
        ]);

        $world = World::find($validated['world_id']);
        if ($world === null || ! $world->canEditContent($user)) {
            return Response::error('World not found, or you do not have permission to edit its content.');
        }

        // Mirrors CompendiumController::store so the MCP create path stays in step with the web one: monsters
        // seed a blank stat block, everything else starts empty and is filled via the field editor later.
        $item = $world->compendiumItems()->create([
            'user_id' => $user->id,
            'item_type' => $validated['item_type'],
            'slug' => Str::slug($validated['name']).'-'.Str::lower(Str::random(4)),
            'name' => $validated['name'],
            'provider' => 'custom',
            'fields' => $validated['item_type'] === 'monster' ? ['block' => Statblock::empty()] : [],
            'document' => $validated['item_type'] === 'monster'
                ? Statblock::toMarkdown(Statblock::empty(), $validated['name'])
                : '',
        ]);

        return Response::text((string) json_encode([
            'created' => true,
            'item' => [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'type' => $item->item_type,
                'edit_url' => route('compendium.edit', $item->id),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'world_id' => $schema->integer()
                ->description('The ID of the world to create the entry in (must be one you can edit).')
                ->required(),
            'name' => $schema->string()
                ->description('The entry\'s name.')
                ->max(160)
                ->required(),
            'item_type' => $schema->string()
                ->description('The kind of entry to create.')
                ->enum(Compendium::keys())
                ->required(),
        ];
    }
}
