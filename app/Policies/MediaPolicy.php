<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;

class MediaPolicy
{
    /** Media belongs to a world; whoever runs that world manages it (admins pass via Gate::before). */
    public function update(User $user, Media $media): bool
    {
        return $this->managesWorld($user, $media) || $media->user_id === $user->id;
    }

    public function delete(User $user, Media $media): bool
    {
        return $this->managesWorld($user, $media) || $media->user_id === $user->id;
    }

    protected function managesWorld(User $user, Media $media): bool
    {
        return $media->world !== null && $media->world->canEditContent($user);
    }
}
