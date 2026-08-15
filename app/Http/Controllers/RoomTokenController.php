<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\RoomChanged;
use App\Events\TokenMoved;
use App\Models\Media;
use App\Models\Room;
use App\Models\RoomMessage;
use App\Models\RoomToken;
use App\Services\DdbClient;
use App\Services\DdbImageStore;
use App\Support\Ddb;
use App\Support\Dice;
use App\Support\Realtime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tokens on a battle room — the combatants that also appear on the initiative tracker. The GM adds
 * people, monsters, or D&D Beyond characters; players add their own imported character and, from a
 * GM-curated shortlist, monsters they control (pets, wildshape, summons). Owners control only their
 * own token, the GM any. Every change pokes the room channel so the board stays in sync.
 */
class RoomTokenController extends Controller
{
    /** Add a token: a person (GM), a monster (GM, or a player from the shortlist), or a custom marker (GM). */
    public function store(Request $request, Room $room)
    {
        $viewer = $request->user();
        $isGm = $viewer->can('manage', $room->campaign);
        abort_unless($isGm || $room->isMember($viewer), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'source' => ['required', 'in:person,monster,character,custom'],
            'document_id' => ['nullable', 'integer'],
            'compendium_item_id' => ['nullable', 'integer'],
            'character_id' => ['nullable', 'integer'],
            'label' => ['nullable', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:9'],
            'x' => ['nullable', 'numeric', 'between:0,100'],
            'y' => ['nullable', 'numeric', 'between:0,100'],
            'size' => ['nullable', 'numeric', 'between:0.25,20'],
            'layer' => ['sometimes', Rule::in(['token', 'gm'])],
            'scene' => ['sometimes', 'integer', Rule::exists('room_scenes', 'id')->where('room_id', $room->id)],
        ]);

        $attributes = match ($data['source']) {
            'person' => $this->personAttributes($room, $isGm, $data),
            'monster' => $this->monsterAttributes($room, $isGm, $data),
            'character' => $this->characterAttributes($room, $isGm, $viewer->id, $data),
            'custom' => $this->customAttributes($isGm, $data),
        };

        $token = $room->tokens()->create([
            // The GM places on the scene they're prepping; players always place on the active scene.
            'scene_id' => ($isGm ? ($data['scene'] ?? null) : null) ?? $room->active_scene_id,
            // A player owns whatever they place (so they can move it); the GM's own placements are
            // unowned unless a person token names its player. The person branch sets user_id itself.
            'user_id' => $isGm ? ($attributes['user_id'] ?? null) : $viewer->id,
            'x' => $data['x'] ?? 50,
            'y' => $data['y'] ?? 50,
            'size' => $data['size'] ?? 1,
            'color' => $data['color'] ?? '#d8a94a',
            // Only the GM may stage on the hidden layer; players always place on the token layer.
            'layer' => $isGm ? ($data['layer'] ?? 'token') : 'token',
            ...$attributes,
        ]);

        Realtime::poke(new RoomChanged($room->id, ['tokens']));

        return back()->with('tokenId', $token->id);
    }

    /** Import a D&D Beyond character by link and drop it as a token. GM for anyone; a player for themself. */
    public function importDdb(Request $request, Room $room, DdbClient $ddb, DdbImageStore $images)
    {
        $viewer = $request->user();
        $isGm = $viewer->can('manage', $room->campaign);
        abort_unless($isGm || $room->isMember($viewer), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'url' => ['required', 'string', 'max:300'],
            'owner_user_id' => ['nullable', 'integer'],
            'x' => ['nullable', 'numeric', 'between:0,100'],
            'y' => ['nullable', 'numeric', 'between:0,100'],
            'scene' => ['sometimes', 'integer', Rule::exists('room_scenes', 'id')->where('room_id', $room->id)],
        ]);

        if (preg_match('/characters?\/(\d+)/i', $data['url'], $matches) !== 1) {
            throw ValidationException::withMessages(['url' => 'That does not look like a D&D Beyond character link.']);
        }

        $raw = $ddb->character($matches[1]);
        if ($raw === null) {
            throw ValidationException::withMessages(['url' => 'Could not fetch that character. Is it set to public on D&D Beyond?']);
        }

        $spec = Ddb::characterToToken($raw, $matches[1]);
        // A player imports for themself. The GM may link the character to a room member so that player
        // controls it on login; otherwise it stays GM-controlled (unowned).
        $chosen = $data['owner_user_id'] ?? null;
        $ownerId = $isGm
            ? ($chosen !== null && $room->members()->whereKey($chosen)->exists() ? (int) $chosen : null)
            : $viewer->id;

        $token = $room->tokens()->create([
            'scene_id' => ($isGm ? ($data['scene'] ?? null) : null) ?? $room->active_scene_id,
            'user_id' => $ownerId,
            'kind' => 'player',
            'ddb_character_id' => $matches[1],
            'label' => $spec['name'],
            'image_media_id' => $images->resolve($spec['image']),
            'hp' => $spec['hp'],
            'max_hp' => $spec['max_hp'],
            'ac' => $spec['ac'],
            'notes' => $spec['notes'],
            'color' => '#6c8cff',
            'x' => $data['x'] ?? 50,
            'y' => $data['y'] ?? 50,
            'size' => 1,
        ]);

        Realtime::poke(new RoomChanged($room->id, ['tokens']));

        return back()->with('tokenId', $token->id);
    }

    public function update(Request $request, RoomToken $token)
    {
        $isGm = $this->authorizeToken($request, $token);

        $data = $request->validate([
            'x' => ['sometimes', 'numeric', 'between:0,100'],
            'y' => ['sometimes', 'numeric', 'between:0,100'],
            'size' => ['sometimes', 'numeric', 'between:0.25,20'],
            'label' => ['nullable', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:9'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'hp' => ['nullable', 'integer', 'min:0'],
            'max_hp' => ['nullable', 'integer', 'min:0'],
            'ac' => ['nullable', 'integer', 'min:0'],
            'initiative' => ['nullable', 'integer'],
            'elevation' => ['nullable', 'integer', 'between:-1000,10000'],
            'in_tracker' => ['sometimes', 'boolean'],
            'layer' => ['sometimes', Rule::in(['token', 'gm'])],
            'conditions' => ['nullable', 'array'],
            'conditions.*' => ['string', 'max:32'],
            // Live combat state edited from the character sheet.
            'temp_hp' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'death_success' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'death_fail' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'exhaustion' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'concentrating_on' => ['sometimes', 'nullable', 'string', 'max:120'],
            'spell_slots_used' => ['sometimes', 'array'],
            'spell_slots_used.*' => ['integer', 'min:0', 'max:20'],
            'hit_dice_used' => ['sometimes', 'array'],
            'hit_dice_used.*' => ['integer', 'min:0', 'max:40'],
            // Sheet edits that layer over the D&D Beyond base (prepared spells, equipped/attuned items,
            // currency) so a Refresh re-pulls the sheet without clobbering the player's choices.
            'sheet_state' => ['sometimes', 'array'],
            'sheet_state.prepared' => ['sometimes', 'array'],
            'sheet_state.prepared.*' => ['boolean'],
            'sheet_state.equipped' => ['sometimes', 'array'],
            'sheet_state.equipped.*' => ['boolean'],
            'sheet_state.attuned' => ['sometimes', 'array'],
            'sheet_state.attuned.*' => ['boolean'],
            'sheet_state.currency' => ['sometimes', 'array'],
            'sheet_state.currency.*' => ['integer', 'min:0', 'max:9999999'],
            'compendium_item_id' => ['nullable', 'integer', Rule::exists('campaign_compendium_items', 'id')->where('world_id', $token->room->campaign->world_id)],
        ]);

        // Linking a stat block and staging on the GM layer are GM acts; a player can't do either.
        if (! $isGm) {
            unset($data['compendium_item_id'], $data['layer']);
        }
        // An empty concentration string means "not concentrating".
        if (($data['concentrating_on'] ?? null) === '') {
            $data['concentrating_on'] = null;
        }

        // Snapshot the effective HP pool (HP + temp) so we can detect damage to a concentrating token
        // after the write — this covers every path (sheet, tracker, GM edit), not just the damage box.
        $wasConcentrating = filled($token->concentrating_on);
        $poolBefore = (int) $token->hp + (int) $token->temp_hp;

        $postedPrompt = DB::transaction(function () use ($token, $data, $wasConcentrating, $poolBefore, $request): bool {
            $token->update($data);

            if (! $wasConcentrating || blank($token->concentrating_on)) {
                return false;
            }
            // Dropping to 0 HP ends concentration outright; otherwise damage taken prompts a save.
            if ((int) $token->hp <= 0) {
                $token->update(['concentrating_on' => null]);

                return false;
            }
            $damage = $poolBefore - ((int) $token->hp + (int) $token->temp_hp);
            if ($damage > 0) {
                $this->postConcentrationPrompt($request, $token, $damage);

                return true;
            }

            return false;
        });

        // A pure position move of a visible token broadcasts the new coords for instant client-side
        // patching (no reload). Anything else — or a hidden GM-layer token — uses a scoped reload of
        // just the tokens (plus messages when a concentration prompt was posted to chat).
        $keys = array_keys($data);
        $positionOnly = $keys !== [] && array_diff($keys, ['x', 'y']) === [];
        $onActiveScene = $token->scene_id === null || $token->scene_id === $token->room->active_scene_id;
        if ($positionOnly && $token->layer !== 'gm' && $onActiveScene) {
            Realtime::poke(new TokenMoved($token->room_id, $token->id, $token->x, $token->y));
        } else {
            Realtime::poke(new RoomChanged($token->room_id, $postedPrompt ? ['tokens', 'messages'] : ['tokens']));
        }

        return back();
    }

    /**
     * Spend Hit Dice on a roster-character token: roll them server-side (die + CON each, floored at 0),
     * heal up to max HP, and post the roll to chat so the table sees it. Pass `die` to spend one of a
     * specific size, or `dice` to spend that many (largest die first).
     */
    public function spendHitDice(Request $request, RoomToken $token)
    {
        $this->authorizeToken($request, $token);

        $data = $request->validate([
            'dice' => ['sometimes', 'integer', 'min:1', 'max:40'],
            'die' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $token->load('character');
        $character = $token->character;
        $pools = $character?->sheet['hit_dice'] ?? [];
        if ($character === null || $pools === []) {
            return back();
        }

        $conMod = (int) floor(((int) ($character->stats['con'] ?? 10) - 10) / 2);
        $used = $token->hit_dice_used ?? [];
        $rollsByDie = fn (int $die): int => (int) ($used[$die] ?? $used[(string) $die] ?? 0);

        // How many dice to spend, and (optionally) restricted to a single die size.
        $requested = isset($data['die']) ? 1 : (int) ($data['dice'] ?? 1);
        $onlyDie = $data['die'] ?? null;

        $rolls = [];
        $heal = 0;
        foreach ($pools as $pool) {
            $die = (int) $pool['die'];
            if ($onlyDie !== null && $die !== (int) $onlyDie) {
                continue;
            }
            $available = (int) $pool['total'] - $rollsByDie($die);
            while ($requested > 0 && $available > 0) {
                $result = random_int(1, $die);
                $rolls[] = "d{$die} [{$result}]";
                $heal += max(0, $result + $conMod);
                $used[$die] = $rollsByDie($die) + 1;
                $available--;
                $requested--;
            }
        }

        if ($rolls === []) {
            return back();
        }

        $before = (int) ($token->hp ?? 0);
        $ceiling = $token->max_hp ?? ($before + $heal);
        $healed = min($ceiling, $before + $heal) - $before;

        $conText = $conMod !== 0
            ? ($conMod > 0 ? " + {$conMod}" : ' − '.abs($conMod)).' CON each'
            : '';
        // Show the full amount rolled, even when only part of it could be applied (already near max HP).
        $roll = [
            'expr' => 'Hit Dice',
            'total' => $heal,
            'detail' => implode(', ', $rolls).$conText,
            'dropped' => [],
            'mode' => null,
            'd20' => null,
        ];

        DB::transaction(function () use ($token, $before, $healed, $used, $roll, $request): void {
            $token->update(['hp' => $before + $healed, 'hit_dice_used' => $used]);
            $token->room->messages()->create([
                'user_id' => $request->user()->id,
                'to_user_id' => null,
                'body' => '',
                'roll' => $roll,
            ]);
        });
        Realtime::poke(new RoomChanged($token->room_id, ['tokens', 'messages']));

        return back();
    }

    /**
     * Resolve a pending concentration prompt: roll a CON save server-side (CON save mod less the 2024
     * exhaustion penalty, floored at 1), replace the prompt with the result card, and drop concentration
     * on a failure. Advantage/disadvantage roll two d20s and keep the higher/lower. Only the token's
     * owner or the GM may roll it.
     */
    public function concentrationSave(Request $request, RoomMessage $message)
    {
        $data = $request->validate([
            'mode' => ['sometimes', 'nullable', Rule::in(['advantage', 'disadvantage'])],
        ]);

        $roll = $message->roll ?? [];
        abort_unless(($roll['pending'] ?? null) === 'concentration', Response::HTTP_NOT_FOUND);

        $token = $message->room->tokens()->find($roll['token_id'] ?? 0);
        abort_if($token === null, Response::HTTP_NOT_FOUND);
        $this->authorizeToken($request, $token);

        $token->loadMissing('character');
        $bonus = $this->conSaveMod($token) - 2 * (int) $token->exhaustion;
        $dice = match ($data['mode'] ?? null) {
            'advantage' => '2d20kh1',
            'disadvantage' => '2d20kl1',
            default => '1d20',
        };
        $result = Dice::roll($dice.($bonus >= 0 ? "+{$bonus}" : (string) $bonus));
        // Dice::roll always parses a well-formed "Nd20±N", so a result is guaranteed here.
        if ($result === null) {
            return back();
        }

        $dc = (int) ($roll['dc'] ?? 10);
        $held = $result['total'] >= $dc;
        $resolved = [
            ...$result,
            'expr' => "CON save vs DC {$dc}",
            'detail' => $result['detail'].' — '.($held ? 'held' : 'broken'),
        ];

        DB::transaction(function () use ($message, $resolved, $held, $token): void {
            $message->update(['roll' => $resolved]);
            if (! $held) {
                $token->update(['concentrating_on' => null]);
            }
        });
        Realtime::poke(new RoomChanged($message->room_id, ['messages', 'tokens']));

        return back();
    }

    /**
     * Whisper a concentration-save prompt to the token's owner and the GM (an unowned monster's goes to
     * the GM alone), each with a Roll button. The DC is half the damage taken, minimum 10.
     */
    private function postConcentrationPrompt(Request $request, RoomToken $token, int $damage): void
    {
        $token->room->messages()->create([
            'user_id' => $request->user()->id,
            'to_user_id' => $token->user_id ?? $token->room->campaign->world->user_id,
            'body' => '',
            'roll' => [
                'pending' => 'concentration',
                'token_id' => $token->id,
                'dc' => max(10, intdiv($damage, 2)),
                'spell' => $token->concentrating_on,
                'total' => null,
                'expr' => null,
                'detail' => null,
                'dropped' => [],
                'mode' => null,
                'd20' => null,
            ],
        ]);
    }

    /** The token's Constitution saving-throw modifier, from its character sheet (0 if it isn't a character). */
    private function conSaveMod(RoomToken $token): int
    {
        $character = $token->character;
        if ($character === null) {
            return 0;
        }

        foreach ($character->sheet['saves'] ?? [] as $save) {
            if (($save['ability'] ?? null) === 'CON') {
                return (int) ($save['mod'] ?? 0);
            }
        }

        return (int) floor(((int) ($character->stats['con'] ?? 10) - 10) / 2);
    }

    /** Upload a portrait shown inside the token circle. */
    public function uploadImage(Request $request, RoomToken $token)
    {
        $this->authorizeToken($request, $token);

        $request->validate([
            'file' => ['required', 'file', 'mimes:'.implode(',', config('media.accept')), 'max:51200'],
        ]);

        $disk = config('media.disk');
        $file = $request->file('file');
        if (! $request->user()->canStore((int) $file->getSize())) {
            return back()->withErrors(['file' => 'Storage limit reached — delete some media or upgrade your plan for more space.']);
        }

        $media = Media::create([
            'user_id' => $request->user()->id,
            'world_id' => $token->room->campaign->world_id,
            'disk' => $disk,
            'path' => $file->store('media', $disk),
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        $token->update(['image_media_id' => $media->id]);
        Realtime::poke(new RoomChanged($token->room_id, ['tokens']));

        return back();
    }

    public function destroy(Request $request, RoomToken $token)
    {
        $this->authorizeToken($request, $token);

        $roomId = $token->room_id;
        $token->delete();
        Realtime::poke(new RoomChanged($roomId, ['tokens']));

        return back();
    }

    /**
     * A GM-placed token for a People entry (an npc document) of this world. GM-controlled.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function personAttributes(Room $room, bool $isGm, array $data): array
    {
        abort_unless($isGm, Response::HTTP_FORBIDDEN);

        $documentId = $data['document_id'] ?? null;
        if ($documentId === null) {
            throw ValidationException::withMessages(['document_id' => 'Choose a person.']);
        }

        $document = $room->campaign->world->documents()->where('kind', 'npc')->whereKey($documentId)->first();
        if ($document === null) {
            throw ValidationException::withMessages(['document_id' => 'That person is not in this world.']);
        }

        return [
            'document_id' => $document->id,
            'kind' => 'person',
            'label' => $document->title,
        ];
    }

    /**
     * A monster token from the campaign compendium. The GM may add any; a player only from the room's
     * shortlist (pets, wildshape, summons). HP/AC/portrait are seeded from the stat block.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function monsterAttributes(Room $room, bool $isGm, array $data): array
    {
        $itemId = $data['compendium_item_id'] ?? null;
        if ($itemId === null) {
            throw ValidationException::withMessages(['compendium_item_id' => 'Choose a monster.']);
        }

        $allowed = $isGm || in_array((int) $itemId, array_map('intval', $room->player_monster_ids ?? []), true);
        abort_unless($allowed, Response::HTTP_FORBIDDEN);

        $item = $room->campaign->world->compendiumItems()
            ->where('item_type', 'monster')->whereKey($itemId)->first();
        if ($item === null) {
            throw ValidationException::withMessages(['compendium_item_id' => 'That monster is not in this world.']);
        }

        $hp = $this->leadingInt(data_get($item->fields, 'block.hp'));

        return [
            'compendium_item_id' => $item->id,
            'kind' => 'monster',
            'label' => $item->name,
            'image_media_id' => $item->image_media_id,
            'hp' => $hp,
            'max_hp' => $hp,
            'ac' => $this->leadingInt(data_get($item->fields, 'block.ac')),
        ];
    }

    /**
     * A token from the party roster. The GM may drop any character; a player only their own. Owner,
     * portrait, HP and AC are seeded from the Character so the owning player controls it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function characterAttributes(Room $room, bool $isGm, int $viewerId, array $data): array
    {
        $characterId = $data['character_id'] ?? null;
        if ($characterId === null) {
            throw ValidationException::withMessages(['character_id' => 'Choose a character.']);
        }

        $character = $room->campaign->characters()->whereKey($characterId)->first();
        if ($character === null) {
            throw ValidationException::withMessages(['character_id' => 'That character is not in this world.']);
        }

        // A player may only place their own character.
        abort_unless($isGm || $character->user_id === $viewerId, Response::HTTP_FORBIDDEN);

        return [
            'user_id' => $character->user_id, // the owning player controls the token
            'character_id' => $character->id,
            'kind' => 'player',
            'label' => $character->name,
            'image_media_id' => $character->image_media_id,
            'hp' => $character->hp,
            'max_hp' => $character->max_hp,
            'ac' => $character->ac,
            'color' => '#6c8cff',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function customAttributes(bool $isGm, array $data): array
    {
        abort_unless($isGm, Response::HTTP_FORBIDDEN);

        return [
            'kind' => 'custom',
            'label' => $data['label'] ?? null,
        ];
    }

    /** First integer in a stat-block string like "45 (6d10+12)" or "15 (natural armor)". */
    private function leadingInt(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        return preg_match('/\d+/', (string) $value, $matches) === 1 ? (int) $matches[0] : null;
    }

    /** Only the token's owner or the room's GM may edit or remove it. Returns whether the viewer is the GM. */
    private function authorizeToken(Request $request, RoomToken $token): bool
    {
        $viewer = $request->user();
        $isGm = $viewer->can('manage', $token->room->campaign);
        abort_unless($isGm || $token->user_id === $viewer->id, Response::HTTP_FORBIDDEN);

        return $isGm;
    }
}
