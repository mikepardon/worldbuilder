<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\World;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the worlds the authenticated user owns or co-authors, with the IDs the other tools need.')]
class ListWorlds extends Tool
{
    public function handle(Request $request): Response
    {
        $user = $request->user();
        if ($user === null) {
            return Response::error('You must be authenticated to use this tool.');
        }

        $worlds = World::query()
            ->where('user_id', $user->id)
            ->orWhereHas('members', fn (Builder $query) => $query->where('user_id', $user->id))
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (World $world): array => [
                'id' => $world->id,
                'name' => $world->name,
                'slug' => $world->slug,
            ])->all();

        return Response::text((string) json_encode(['worlds' => $worlds], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
