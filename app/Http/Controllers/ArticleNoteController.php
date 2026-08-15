<?php

namespace App\Http\Controllers;

use App\Models\ArticleNote;
use App\Models\Document;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ArticleNoteController extends Controller
{
    public function store(Request $request, Document $document)
    {
        abort_unless($document->world->readerAllowsNotes(), Response::HTTP_FORBIDDEN);

        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $document->notes()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
            'shared' => false,
        ]);

        return back();
    }

    public function share(Request $request, ArticleNote $note)
    {
        abort_unless($note->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
        $note->update(['shared' => ! $note->shared]);

        return back();
    }

    public function destroy(Request $request, ArticleNote $note)
    {
        abort_unless($note->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);
        $note->delete();

        return back();
    }
}
