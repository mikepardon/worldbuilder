<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateCompendiumItem;
use App\Mcp\Tools\ListWorlds;
use App\Mcp\Tools\SearchCompendium;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The Worldbuilder MCP server. External AI clients connect with a Sanctum personal access token (Bearer)
 * and act strictly as that user: every tool is scoped, server-side, to the worlds they own or co-author.
 */
#[Name('Worldbuilder')]
#[Version('0.1.0')]
#[Instructions(<<<'MARKDOWN'
    Read and manage the authenticated user's Worldbuilder worlds. Every tool is scoped to worlds the user
    owns or co-authors — you can never see or change another user's world. Call `list-worlds` first to
    discover the world IDs the other tools need.
    MARKDOWN)]
class WorldbuilderServer extends Server
{
    /** @var array<int, class-string<\Laravel\Mcp\Server\Tool>> */
    protected array $tools = [
        ListWorlds::class,
        SearchCompendium::class,
        CreateCompendiumItem::class,
    ];
}
