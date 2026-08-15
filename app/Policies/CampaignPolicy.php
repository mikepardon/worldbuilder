<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    /** The GM (world owner) or a member of the campaign may view it. */
    public function view(User $user, Campaign $campaign): bool
    {
        return $this->manage($user, $campaign) || $campaign->hasMember($user);
    }

    /**
     * Run this campaign: the world's owner, a world moderator, or a co-GM of this campaign
     * (admins bypass via Gate::before).
     */
    public function manage(User $user, Campaign $campaign): bool
    {
        return $campaign->world->canManagePlay($user)
            || $campaign->members()->where('user_id', $user->id)->where('role', 'co-gm')->exists();
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->manage($user, $campaign);
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $this->manage($user, $campaign);
    }
}
