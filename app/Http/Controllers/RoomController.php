<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\RoomChanged;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Map;
use App\Models\Media;
use App\Models\Room;
use App\Models\RoomDrawing;
use App\Models\RoomHandout;
use App\Models\RoomMessage;
use App\Models\RoomNote;
use App\Models\RoomScene;
use App\Models\RoomTemplate;
use App\Models\RoomToken;
use App\Models\User;
use App\Support\Realtime;
use App\Support\Sections;
use App\Support\WorldNav;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** Battle rooms: GM management, join-by-code, and the shared live board. */
class RoomController extends Controller
{
    public function index(Campaign $campaign)
    {
        $this->authorize('manage', $campaign);

        $rooms = $campaign->rooms()->withCount(['tokens', 'members'])->latest()->get();

        return Inertia::render('Rooms/Index', [
            'world' => WorldNav::for($campaign->world),
            'campaign' => ['id' => $campaign->id, 'name' => $campaign->name],
            'rooms' => $rooms->map(fn (Room $room) => [
                'id' => $room->id, 'name' => $room->name, 'code' => $room->code,
                'tokens_count' => $room->tokens_count, 'members_count' => $room->members_count,
            ]),
        ]);
    }

    public function store(Request $request, Campaign $campaign)
    {
        $this->authorize('manage', $campaign);

        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $room = $campaign->rooms()->create(['name' => $data['name'], 'created_by' => $request->user()->id]);

        return redirect()->route('rooms.show', [$campaign, $room]);
    }

    public function show(Request $request, Campaign $campaign, Room $room)
    {
        $viewer = $request->user();
        $isGm = $viewer->can('manage', $room->campaign); // owner or platform admin
        abort_unless($isGm || $room->isMember($viewer), Response::HTTP_FORBIDDEN);

        $room->load([
            'activeScene.image', 'scenes:id,room_id,name,sort',
            'tokens.owner:id,name', 'tokens.image',
            'tokens.compendiumItem:id,name,fields,image_media_id', 'tokens.compendiumItem.image',
            'tokens.document:id,title,summary,slug,kind',
            'tokens.character:id,name,level,class,race,stats,sheet',
            'members:id,name',
            'messages' => fn ($query) => $query->latest()->limit(50)->with(['user:id,name', 'recipient:id,name']),
            'notes' => fn ($query) => $query->where('user_id', $viewer->id)->latest(),
        ]);

        // Scenes own the map/grid/fog. Players always see the active (live) scene; the GM may preview
        // and prep another scene via ?scene= without changing what players see. Legacy rows with a
        // null scene_id fall back to the viewed scene.
        // Load the requested scene fully (its image/grid/fog) — the `scenes` list above is column-trimmed
        // for the picker, so it can't be the board's source.
        $requestedScene = $isGm && $request->integer('scene') > 0
            ? $room->scenes()->with('image')->find($request->integer('scene'))
            : null;
        $scene = $requestedScene ?? $room->activeScene;
        $sceneId = $scene?->id;
        $onActiveScene = fn ($object) => $object->scene_id === $sceneId || $object->scene_id === null;

        // Resolve a message author to their room identity: "Game Master" for the GM, else their
        // character name + portrait, falling back to the account name.
        $campaign = $room->campaign;
        $charByUser = $campaign->characters()->with('image')->get()->groupBy('user_id');
        $displayFor = function (?User $author) use ($campaign, $charByUser): array {
            if ($author === null) {
                return ['name' => 'Unknown', 'image' => null];
            }
            if ($author->can('manage', $campaign)) {
                return ['name' => 'Game Master', 'image' => null];
            }
            $character = $charByUser->get($author->id)?->first();

            return ['name' => $character?->name ?? $author->name, 'image' => $character?->image?->url];
        };

        // A distinct, stable nameplate colour per person: the GM in amber, players from a spread palette.
        $palette = ['#6c8cff', '#e06c9f', '#4fd1a1', '#f2a154', '#b98cff', '#5cc8e6', '#f2c14e', '#ff8f6b', '#8fd0ff', '#a3d977'];
        $memberIds = $room->members->pluck('id')->sort()->values();
        $colorFor = function (?int $userId) use ($campaign, $palette, $memberIds): string {
            if ($userId === null) {
                return '#9aa4b2';
            }
            if ($userId === $campaign->world->user_id) {
                return '#d8a94a';
            }
            $index = $memberIds->search($userId);

            return $index === false ? '#9aa4b2' : $palette[$index % count($palette)];
        };

        // Monsters the GM has cleared for players to add themselves (pets, wildshape, summons).
        $shortlist = filled($room->player_monster_ids)
            ? $campaign->world->compendiumItems()->where('item_type', 'monster')
                ->whereIn('id', $room->player_monster_ids)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($item) => ['id' => $item->id, 'name' => $item->name])->values()
            : collect();

        return Inertia::render('Rooms/Show', [
            'campaign' => ['id' => $room->campaign_id, 'name' => $campaign->name],
            'isGm' => $isGm,
            'me' => [
                'id' => $viewer->id,
                'name' => $viewer->name,
                'display' => Character::roomDisplayName($viewer, $room),
            ],
            // STUN/TURN for the voice/video mesh (browser RTCPeerConnection config).
            'iceServers' => config('webrtc.ice_servers'),
            // The viewer's private session journal for this room.
            'journal' => $room->notes->map(fn (RoomNote $note) => [
                'id' => $note->id,
                'body' => $note->body,
                'at' => $note->created_at?->diffForHumans(),
            ]),
            // GM handouts shared with the whole table (image and/or note); only the GM may manage them.
            'handouts' => $room->handouts()->with('image')->latest()->get()->map(fn (RoomHandout $handout) => [
                'id' => $handout->id,
                'title' => $handout->title,
                'body' => $handout->body,
                'image_url' => $handout->image?->url,
                'at' => $handout->created_at?->diffForHumans(),
                'can_manage' => $isGm,
            ]),
            'room' => [
                'id' => $room->id, 'name' => $room->name, 'code' => $room->code,
                // Map/grid/fog come from the active scene (the board's contract is unchanged).
                'image_url' => $scene?->image?->url, 'image_media_id' => $scene?->image_media_id,
                'grid_visible' => $scene?->grid_visible ?? true, 'grid_size' => $scene?->grid_size ?? 20,
                'unit_size' => $scene?->unit_size ?? 5, 'unit' => $scene?->unit ?? 'ft',
                'fog_enabled' => $scene?->fog_enabled ?? false, 'fog' => $scene?->fog ?? [],
                'round' => $room->round, 'active_token_id' => $room->active_token_id,
                'active_scene_id' => $room->active_scene_id,
                'viewing_scene_id' => $sceneId,
                'players_see_tracker' => $room->players_see_tracker,
                'voice_enabled' => $room->voice_enabled,
                'player_monster_ids' => $isGm ? array_map('intval', $room->player_monster_ids ?? []) : [],
                'members' => $room->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name]),
                // The GM's scene list: `active` is live (players see it), `viewing` is the GM's preview.
                'scenes' => $isGm
                    ? $room->scenes->sortBy('sort')->values()->map(fn (RoomScene $s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'active' => $s->id === $room->active_scene_id,
                        'viewing' => $s->id === $sceneId,
                    ])
                    : [],
            ],
            // These four collections are separate top-level props (not nested under `room`) so a realtime
            // reload can refresh just the one that changed — a chat message doesn't re-serialise every
            // token's character sheet, a token move doesn't re-fetch the whole message log, and so on.
            // Each is a closure so a partial reload only computes the prop it actually requested.
            // GM-layer tokens are staged privately — players never receive them.
            'tokens' => fn () => $room->tokens
                ->filter($onActiveScene)
                ->filter(fn (RoomToken $token) => $isGm || $token->layer !== 'gm')
                ->map(fn (RoomToken $token) => $this->token($token, $viewer->id, $isGm))
                ->values(),
            // Placed AoE templates (spell shapes); GM-layer ones are hidden from players.
            'templates' => fn () => $room->templates
                ->filter($onActiveScene)
                ->filter(fn (RoomTemplate $template) => $isGm || $template->layer !== 'gm')
                ->map(fn (RoomTemplate $template) => [
                    'id' => $template->id,
                    'kind' => $template->kind,
                    'layer' => $template->layer,
                    'x' => $template->x,
                    'y' => $template->y,
                    'length' => $template->length,
                    'angle' => $template->angle,
                    'color' => $template->color,
                    'can_remove' => $isGm || $template->created_by === $viewer->id,
                ])->values(),
            // GM freehand/shape annotations; GM-layer drawings are hidden from players.
            'drawings' => fn () => $room->drawings
                ->filter($onActiveScene)
                ->filter(fn (RoomDrawing $drawing) => $isGm || $drawing->layer !== 'gm')
                ->map(fn (RoomDrawing $drawing) => [
                    'id' => $drawing->id,
                    'kind' => $drawing->kind,
                    'layer' => $drawing->layer,
                    'points' => $drawing->points,
                    'color' => $drawing->color,
                    'fill' => $drawing->fill,
                ])->values(),
            // Private messages are filtered server-side: a whisper reaches only its sender,
            // recipient, and the GM. Oldest-first for display (loaded newest-first for the limit).
            'messages' => fn () => $room->messages
                ->filter(fn (RoomMessage $message) => $message->to_user_id === null || $isGm
                    || $message->user_id === $viewer->id || $message->to_user_id === $viewer->id)
                ->sortBy('id')->values()->map(function (RoomMessage $message) use ($displayFor, $colorFor) {
                    $author = $displayFor($message->user);

                    return [
                        'id' => $message->id,
                        'user' => $author['name'],
                        'user_image' => $author['image'],
                        'user_color' => $colorFor($message->user_id),
                        'user_id' => $message->user_id,
                        'body' => $message->body,
                        'roll' => $message->roll,
                        'private' => $message->to_user_id !== null,
                        'to' => $message->recipient?->name,
                    ];
                }),
            // Monsters the GM can drop as tokens (and pick a player shortlist from).
            'monsters' => $isGm
                ? $campaign->world->compendiumItems()->where('item_type', 'monster')->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values()
                : [],
            // The GM's monster shortlist, resolved for the player "add" picker (visible to everyone).
            'playerMonsters' => $shortlist,
            // Roster characters to drop as tokens: the GM sees all, a player only their own.
            'characters' => ($isGm
                ? $campaign->characters()
                : $campaign->characters()->where('user_id', $viewer->id))
                ->with('image')->orderBy('name')->get()
                ->map(fn ($character) => [
                    'id' => $character->id,
                    'name' => $character->name,
                    'image_url' => $character->image?->url,
                ])->values(),
        ]);
    }

    public function update(Request $request, Room $room)
    {
        $this->authorize('manage', $room->campaign);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'image_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'grid_visible' => ['sometimes', 'boolean'],
            'grid_size' => ['sometimes', 'integer', 'between:2,500'],
            'unit_size' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:12'],
            'fog_enabled' => ['sometimes', 'boolean'],
            'fog' => ['nullable', 'array'],
            'fog.*' => ['string', 'max:16'],
            'players_see_tracker' => ['sometimes', 'boolean'],
            'voice_enabled' => ['sometimes', 'boolean'],
            'round' => ['sometimes', 'integer', 'min:1'],
            'active_token_id' => ['nullable', 'integer', Rule::exists('room_tokens', 'id')->where('room_id', $room->id)],
            'player_monster_ids' => ['sometimes', 'array'],
            'player_monster_ids.*' => ['integer', Rule::exists('campaign_compendium_items', 'id')->where('world_id', $room->campaign->world_id)],
            'scene' => ['sometimes', 'integer', Rule::exists('room_scenes', 'id')->where('room_id', $room->id)],
        ]);

        // Map/grid/fog belong to a scene — the one the GM is prepping (`scene`), else the active one.
        // The rest belong to the room.
        $sceneKeys = ['image_media_id', 'grid_visible', 'grid_size', 'unit_size', 'unit', 'fog_enabled', 'fog'];
        $sceneData = array_intersect_key($data, array_flip($sceneKeys));
        $roomData = array_diff_key($data, array_flip([...$sceneKeys, 'scene']));

        if ($roomData !== []) {
            $room->update($roomData);
        }
        if ($sceneData !== []) {
            $scene = $room->scenes()->find($data['scene'] ?? null) ?? $room->activeScene;
            $scene?->update($sceneData);
        }
        Realtime::poke(new RoomChanged($room->id));

        return back();
    }

    public function destroy(Room $room)
    {
        $this->authorize('manage', $room->campaign);
        $campaign = $room->campaign;
        $room->delete();

        return redirect()->route('rooms.index', $campaign);
    }

    public function uploadImage(Request $request, Room $room)
    {
        $this->authorize('manage', $room->campaign);

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
            'world_id' => $room->campaign->world_id,
            'disk' => $disk,
            'path' => $file->store('media', $disk),
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        $scene = $room->scenes()->find($request->integer('scene')) ?? $room->activeScene;
        $scene?->update(['image_media_id' => $media->id]);
        Realtime::poke(new RoomChanged($room->id));

        return back();
    }

    /** Map sources the GM can pick instead of uploading: the world's images and any location maps. */
    public function mapOptions(Request $request, Room $room): JsonResponse
    {
        $this->authorize('manage', $room->campaign);

        $perPage = 24;
        $page = max(1, $request->integer('page', 1));
        $search = trim((string) $request->string('search'));

        $world = $room->campaign->world;
        $mediaQuery = Media::where('world_id', $world->id)->where('mime', 'like', 'image/%')
            ->when($search !== '', fn ($query) => $query->where('filename', 'like', '%'.$search.'%'))
            ->latest();
        $total = (clone $mediaQuery)->count();
        $media = $mediaQuery->forPage($page, $perPage)->get()
            ->map(fn (Media $item) => ['id' => $item->id, 'url' => $item->url, 'filename' => $item->filename])
            ->values();

        // Location maps are few; send them once with the first page, filtered by the same search.
        $locations = $page === 1
            ? $world->maps()->whereNotNull('image_media_id')
                ->with(['image', 'document:id,title'])->orderBy('name')->get()
                ->map(fn (Map $map) => [
                    'image_media_id' => $map->image_media_id,
                    'name' => $map->document?->title ?? $map->name,
                    'image_url' => $map->image?->url,
                ])
                ->filter(fn (array $map) => $search === '' || str_contains(mb_strtolower($map['name']), mb_strtolower($search)))
                ->values()
            : collect();

        return response()->json([
            'media' => $media,
            'locations' => $locations,
            'page' => $page,
            'has_more' => $page * $perPage < $total,
        ]);
    }

    /** Landing page for a shared join link. */
    public function joinShow(Request $request, string $code)
    {
        $room = Room::where('code', $code)->firstOrFail();
        $viewer = $request->user();

        if ($viewer && ($viewer->can('manage', $room->campaign) || $room->isMember($viewer))) {
            return redirect()->route('rooms.show', [$room->campaign_id, $room]);
        }

        return Inertia::render('Rooms/Join', [
            'room' => ['name' => $room->name, 'code' => $room->code, 'campaign' => $room->campaign->name],
            // Only players of this world may join a room; others are told to ask the GM.
            'canJoin' => $viewer !== null && $room->campaign->hasMember($viewer),
        ]);
    }

    public function join(Request $request, string $code)
    {
        $room = Room::where('code', $code)->firstOrFail();
        $viewer = $request->user();
        $isGm = $viewer->can('manage', $room->campaign);

        // Rooms are for the campaign's players — you must belong to the world to join.
        abort_unless($isGm || $room->campaign->hasMember($viewer), Response::HTTP_FORBIDDEN, 'Ask your GM to add you to this world first.');

        if (! $isGm) {
            $room->members()->syncWithoutDetaching([$viewer->id]);
            Realtime::poke(new RoomChanged($room->id));
        }

        return redirect()->route('rooms.show', [$room->campaign_id, $room]);
    }

    /** The GM removes a player from a room: drop their membership and clear their tokens off the board. */
    public function kick(Request $request, Room $room, User $user)
    {
        $this->authorize('manage', $room->campaign);

        DB::transaction(function () use ($room, $user): void {
            $room->members()->detach($user->id);
            $room->tokens()->where('user_id', $user->id)->delete();
        });

        Realtime::poke(new RoomChanged($room->id));

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function token(RoomToken $token, int $viewerId, bool $isGm): array
    {
        $isOwner = $token->user_id === $viewerId;
        // Players see full stats for tokens they control; the GM sees everything.
        $seesStats = $isGm || $isOwner;
        // Party (player-character) HP is visible to the whole table so allies can read the battle;
        // enemy and NPC HP stays with the GM and any owner.
        $seesHp = $seesStats || $token->kind === 'player';

        return [
            'id' => $token->id, 'x' => $token->x, 'y' => $token->y, 'size' => $token->size,
            'label' => $token->label ?: $token->compendiumItem?->name,
            'color' => $token->color, 'kind' => $token->kind, 'layer' => $token->layer,
            'image_url' => $token->image?->url,
            'hp' => $seesHp ? $token->hp : null,
            'max_hp' => $seesHp ? $token->max_hp : null,
            'temp_hp' => $seesHp ? $token->temp_hp : null,
            'ac' => $seesStats ? $token->ac : null,
            // Live combat state, edited from the sheet by the owner or GM.
            'death_success' => $seesStats ? $token->death_success : null,
            'death_fail' => $seesStats ? $token->death_fail : null,
            'exhaustion' => $seesStats ? $token->exhaustion : null,
            'concentrating_on' => $seesStats ? $token->concentrating_on : null,
            'spell_slots_used' => $seesStats ? ($token->spell_slots_used ?? []) : null,
            'hit_dice_used' => $seesStats ? ($token->hit_dice_used ?? []) : null,
            // Sheet edits layered over the D&D Beyond base (prepared, equipped, attuned, currency).
            'sheet_state' => $seesStats ? ($token->sheet_state ?? []) : null,
            'initiative' => $token->initiative,
            'elevation' => $token->elevation,
            'in_tracker' => $token->in_tracker,
            'conditions' => $token->conditions ?? [],
            'ddb_character_id' => $token->ddb_character_id,
            'compendium_item_id' => $token->compendium_item_id,
            'character_id' => $token->character_id,
            // Player notes are for the GM and the token's owner only.
            'notes' => ($isGm || $isOwner) ? $token->notes : null,
            // The linked stat block, so the GM's click-through can render it without another request.
            'statblock' => $isGm && $token->compendiumItem !== null
                ? [
                    'name' => $token->compendiumItem->name,
                    'block' => data_get($token->compendiumItem->fields, 'block'),
                    'image_url' => $token->compendiumItem->image?->url ?? $token->image?->url,
                ]
                : null,
            // The full character sheet, for the owner or GM (shown when a roster character is clicked).
            'character' => $seesStats && $token->character !== null
                ? [
                    'level' => $token->character->level,
                    'class' => $token->character->class,
                    'race' => $token->character->race,
                    'stats' => $token->character->stats,
                    'sheet' => $token->character->sheet,
                ]
                : null,
            // A person token links back to its People entry (title, blurb, and a link).
            'person' => $token->document !== null
                ? [
                    'title' => $token->document->title,
                    'summary' => $token->document->summary,
                    'slug' => $token->document->slug,
                    'type' => Sections::typeSlug($token->document->kind),
                ]
                : null,
            'owner_id' => $token->user_id, 'owner_name' => $token->owner?->name,
            // An owner may move only their own token; the GM may move any.
            'can_edit' => $isGm || $isOwner,
        ];
    }
}
