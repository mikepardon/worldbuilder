<?php

declare(strict_types=1);

use App\Mcp\Servers\WorldbuilderServer;
use Laravel\Mcp\Facades\Mcp;

// The Worldbuilder MCP server. External AI clients authenticate with a Sanctum personal access token
// (Authorization: Bearer <token>) and act strictly as that user. `auth:sanctum` rejects unauthenticated
// callers before any tool runs; the throttle caps abuse. Per-tool authorisation is enforced server-side
// against the user's own worlds — see App\Mcp\Tools.
Mcp::web('/mcp', WorldbuilderServer::class)->middleware(['auth:sanctum', 'throttle:60,1']);
