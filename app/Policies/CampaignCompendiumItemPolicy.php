<?php

namespace App\Policies;

use App\Models\CampaignCompendiumItem;
use App\Models\User;

class CampaignCompendiumItemPolicy
{
    public function view(User $user, CampaignCompendiumItem $item): bool
    {
        return $item->world->isManagedBy($user);
    }

    public function update(User $user, CampaignCompendiumItem $item): bool
    {
        return $item->world->canEditContent($user);
    }

    public function delete(User $user, CampaignCompendiumItem $item): bool
    {
        return $item->world->canEditContent($user);
    }
}
