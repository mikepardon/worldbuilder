<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Polling endpoint for asynchronous AI generations. The browser posts a chat message to a feature's
 * "start" endpoint (which enqueues a job and returns a handle), then polls here until the queued worker
 * has stored the result — so a slow model call never blocks the web request or trips the gateway timeout.
 */
class AiRequestController extends Controller
{
    public function show(Request $request, AiRequest $aiRequest): JsonResponse
    {
        abort_unless($aiRequest->user_id === $request->user()->id, 403);

        return response()->json($aiRequest->toStatusArray());
    }
}
