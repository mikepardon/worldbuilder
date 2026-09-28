<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RuleSystem;
use App\Models\User;

/**
 * Authorises authoring a rule system. Global templates (world_id null) are the admin library and are
 * reached only through the admin Gate::before — this policy denies non-admins. World systems are lore
 * authoring, so a world's owner or co-author may build and edit them.
 */
class RuleSystemPolicy
{
    public function view(User $user, RuleSystem $system): bool
    {
        return $this->authorable($user, $system);
    }

    public function update(User $user, RuleSystem $system): bool
    {
        return $this->authorable($user, $system);
    }

    public function delete(User $user, RuleSystem $system): bool
    {
        return $this->authorable($user, $system);
    }

    private function authorable(User $user, RuleSystem $system): bool
    {
        if ($system->is_template || $system->world === null) {
            return false;
        }

        return $system->world->canEditContent($user);
    }
}
