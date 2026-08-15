<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    /** Owner and co-authors see everything; a player sees non-private documents only. */
    public function view(User $user, Document $document): bool
    {
        $world = $document->world;
        if ($world->isManagedBy($user)) {
            return true;
        }

        return ! $document->is_private && $world->hasMember($user);
    }

    public function create(User $user, Document $document): bool
    {
        return $document->world->canEditContent($user);
    }

    public function update(User $user, Document $document): bool
    {
        return $document->world->canEditContent($user);
    }

    public function delete(User $user, Document $document): bool
    {
        return $document->world->canEditContent($user);
    }
}
