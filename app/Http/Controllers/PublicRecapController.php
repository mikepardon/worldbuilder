<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Recap;
use App\Models\RecapEntity;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public, read-only page for a shared recap, reachable by anyone with the link at /recap/{token} — no
 * account or campaign membership needed. Only a finished, currently-shared recap resolves.
 */
class PublicRecapController extends Controller
{
    public function show(string $token): Response
    {
        $recap = Recap::query()
            ->where('share_token', $token)
            ->where('status', 'done')
            ->with(['session.campaign.world', 'entities'])
            ->first();

        abort_if($recap === null, 404);

        $session = $recap->session;
        $campaign = $session->campaign;

        return Inertia::render('Public/Recap', [
            'session' => ['title' => $session->title],
            'campaign' => ['name' => $campaign->name],
            'world' => ['name' => $campaign->world->name],
            'recap' => [
                'recap_full' => $recap->recap_full,
                'recap_short' => $recap->recap_short,
                'recap_stylized' => $recap->recap_stylized,
                'moments' => $recap->moments ?? [],
                'outline' => $recap->outline ?? [],
                'next_steps' => $recap->next_steps ?? [],
                'entities' => $recap->entities->map(fn (RecapEntity $entity): array => [
                    'name' => $entity->name,
                    'type' => $entity->type,
                    'description' => $entity->description,
                ])->all(),
                // The transcript (raw session audio, GM-only) and the GM's private quality rating are
                // deliberately NOT exposed on the public share page — it is for players/anyone with the link.
            ],
        ]);
    }
}
