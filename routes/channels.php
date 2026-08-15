<?php

use App\Models\Character;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Presence in a battle room: the GM and its members are admitted. Everyone is surfaced by their
// assigned character's name (the GM as "Game Master") so tiles read as the table, not usernames.
Broadcast::channel('rooms.{room}', function (User $user, Room $room) {
    if (! $user->can('manage', $room->campaign) && ! $room->isMember($user)) {
        return null;
    }

    return ['id' => $user->id, 'name' => Character::roomDisplayName($user, $room)];
});
