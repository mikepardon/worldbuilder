<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserNote;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A player's personal notes on their dashboard. Each user only ever touches their own. */
class UserNoteController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
        ]);

        UserNote::create([...$data, 'user_id' => $request->user()->id]);

        return back();
    }

    public function update(Request $request, UserNote $userNote)
    {
        abort_unless($userNote->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);

        $userNote->update($request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
        ]));

        return back();
    }

    public function destroy(Request $request, UserNote $userNote)
    {
        abort_unless($userNote->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
        $userNote->delete();

        return back();
    }
}
