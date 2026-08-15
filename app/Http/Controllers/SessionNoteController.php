<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Session;
use App\Models\SessionNote;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Personal player notes on a session recap, written from the public reader. */
class SessionNoteController extends Controller
{
    public function store(Request $request, Session $session)
    {
        $user = $request->user();
        $campaign = $session->campaign;

        // Only the GM or a member of the campaign may keep notes, and only when the world allows them.
        abort_unless(
            $user !== null && $campaign->world->readerAllowsNotes()
                && ($campaign->world->user_id === $user->id || $campaign->hasMember($user)),
            Response::HTTP_FORBIDDEN,
        );

        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $session->notes()->create(['user_id' => $user->id, 'body' => $data['body']]);

        return back();
    }

    public function destroy(Request $request, SessionNote $note)
    {
        abort_unless($note->user_id === $request->user()?->id, Response::HTTP_FORBIDDEN);

        $note->delete();

        return back();
    }
}
