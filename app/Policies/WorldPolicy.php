<?php

namespace App\Policies;

use App\Models\User;
use App\Models\World;

class WorldPolicy
{
    /** Owner, co-author, or a player (member of a campaign in it) may view. */
    public function view(User $user, World $world): bool
    {
        return $world->isManagedBy($user) || $world->hasMember($user);
    }

    /** Enter the GM workspace: the owner or any collaborator (co-author or moderator). */
    public function manage(User $user, World $world): bool
    {
        return $world->isManagedBy($user);
    }

    /** Edit the world's lore (entries, compendium, maps, media): owner or a co-author. */
    public function editContent(User $user, World $world): bool
    {
        return $world->canEditContent($user);
    }

    /** Run the world's play (create/manage campaigns): owner or a moderator. */
    public function managePlay(User $user, World $world): bool
    {
        return $world->canManagePlay($user);
    }

    /** World settings (visibility, cover, custom domain) are the owner's alone. */
    public function update(User $user, World $world): bool
    {
        return $this->owns($user, $world);
    }

    public function delete(User $user, World $world): bool
    {
        return $this->owns($user, $world);
    }

    /** Inviting and removing co-authors is owner-only. */
    public function manageMembers(User $user, World $world): bool
    {
        return $this->owns($user, $world);
    }

    protected function owns(User $user, World $world): bool
    {
        return $world->user_id === $user->id;
    }
}
