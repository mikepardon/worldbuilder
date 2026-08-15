<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\RoomChanged;
use App\Models\Room;
use App\Models\User;
use App\Support\Dice;
use App\Support\Realtime;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chat + dice for a battle room. Commands:
 *   /roll 2d6+3 (or /r)   → a public roll
 *   /gmroll 2d6 (or /gr)  → a roll only the GM (and roller) sees
 *   /w <name|gm> message  → a private whisper to that player or the GM
 * Rolls are resolved server-side; private messages are filtered server-side on read.
 */
class RoomChatController extends Controller
{
    public function store(Request $request, Room $room)
    {
        $viewer = $request->user();
        $isGm = $viewer->can('manage', $room->campaign);
        abort_unless($isGm || $room->isMember($viewer), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
            // Optional recipient chosen from the chat dropdown: "all", "gm", or a member's user id.
            'to' => ['nullable', 'string', 'max:32'],
            // Optional attribution for a stat-block roll pushed to chat (e.g. a monster).
            'as_name' => ['nullable', 'string', 'max:120'],
            'as_image' => ['nullable', 'string', 'max:2048'],
        ]);
        $body = trim($data['body']);
        $roll = null;
        $toUserId = null;

        if (preg_match('/^\/(gmroll|gr)\s+(.+)/i', $body, $matches)) {
            // GM-only roll: a roll whispered to the GM.
            $roll = Dice::roll($matches[2]);
            $toUserId = $room->campaign->world->user_id;
        } elseif (preg_match('/^\/w(?:hisper)?\s+(\S+)\s+(.+)/i', $body, $matches)) {
            $toUserId = $this->resolveRecipient($room, $matches[1]);
            if ($toUserId === null) {
                throw ValidationException::withMessages(['body' => "No player named “{$matches[1]}” in this room."]);
            }
            $body = $matches[2];
        } elseif (preg_match('/^\/(roll|r)\s+(.+)/i', $body, $matches)) {
            $roll = Dice::roll($matches[2]);
        }

        // A plain message (no slash command) may target a recipient chosen from the dropdown.
        $target = trim((string) ($data['to'] ?? 'all'));
        if ($roll === null && $toUserId === null && $target !== '' && $target !== 'all' && ! str_starts_with($body, '/')) {
            $toUserId = $target === 'gm'
                ? $room->campaign->world->user_id
                : $this->memberOrGmId($room, (int) $target);
            if ($toUserId === null) {
                throw ValidationException::withMessages(['to' => 'That player is not in this room.']);
            }
        }

        // A roll can be attributed to a named creature (its name + portrait) rather than the roller.
        if ($roll !== null && filled($data['as_name'] ?? null)) {
            $roll['as'] = ['name' => $data['as_name'], 'image' => $data['as_image'] ?? null];
        }

        $room->messages()->create(['user_id' => $viewer->id, 'to_user_id' => $toUserId, 'body' => $body, 'roll' => $roll]);
        Realtime::poke(new RoomChanged($room->id, ['messages']));

        return back();
    }

    /** Validate a dropdown-chosen recipient id: it must be a room member or the GM. */
    private function memberOrGmId(Room $room, int $id): ?int
    {
        if ($id === (int) $room->campaign->world->user_id) {
            return $id;
        }

        return $room->members()->whereKey($id)->exists() ? $id : null;
    }

    /** Resolve a whisper target ("gm" or a member/GM name) to a user id, or null if not found. */
    private function resolveRecipient(Room $room, string $token): ?int
    {
        $token = mb_strtolower(trim($token));
        if ($token === 'gm') {
            return $room->campaign->world->user_id;
        }

        $candidates = $room->members()->get(['users.id', 'users.name']);
        $gm = User::find($room->campaign->world->user_id);
        if ($gm !== null) {
            $candidates->push($gm);
        }

        foreach ($candidates as $user) {
            $name = mb_strtolower($user->name);
            if ($name === $token || Str::lower(Str::before($user->name, ' ')) === $token) {
                return (int) $user->id;
            }
        }

        return null;
    }
}
