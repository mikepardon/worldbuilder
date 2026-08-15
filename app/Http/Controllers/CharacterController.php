<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SyncDdbCharacters;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Media;
use App\Models\User;
use App\Services\DdbClient;
use App\Services\DdbImageStore;
use App\Support\Ddb;
use App\Support\WorldNav;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The campaign's character roster. A player owns and edits their own character (optionally linked to a
 * D&D Beyond character for portrait + stats); the GM manages any and can keep NPCs (unowned). Characters
 * are what @-mentions and battle-room tokens reference.
 */
class CharacterController extends Controller
{
    public function index(Request $request, Campaign $campaign)
    {
        $this->authorize('view', $campaign);

        $viewer = $request->user();
        $isGm = $viewer->can('manage', $campaign);

        $campaign->load('characters.owner:id,name', 'characters.image');

        $world = $campaign->world;

        return Inertia::render('Characters/Index', [
            'world' => WorldNav::for($world),
            'campaign' => ['id' => $campaign->id, 'name' => $campaign->name],
            'isGm' => $isGm,
            'me' => ['id' => $viewer->id, 'name' => $viewer->name],
            'characters' => $campaign->characters->sortBy('name')->values()
                ->map(fn (Character $character) => $this->present($character, $viewer->id, $isGm)),
            // For the GM's "owner" picker: the campaign's people.
            'members' => $isGm ? $this->people($campaign) : [],
            // The viewer's personal characters they can attach to this campaign.
            'attachable' => Character::where('user_id', $viewer->id)->whereNull('campaign_id')
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (Character $character) => ['id' => $character->id, 'name' => $character->name])->values(),
            // Whether the GM can pull the linked D&D Beyond campaign's roster.
            'ddbReady' => $isGm && $world->ddb_enabled && filled($world->ddbCobalt()) && filled($world->ddb_campaign_id),
        ]);
    }

    public function store(Request $request, Campaign $campaign, DdbClient $ddb, DdbImageStore $images)
    {
        $viewer = $request->user();
        $isGm = $viewer->can('manage', $campaign);
        abort_unless($isGm || $campaign->hasMember($viewer), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'ddb_url' => ['nullable', 'string', 'max:300'],
            'owner_user_id' => ['nullable', 'integer'],
        ]);

        // A player always owns their own character; the GM may assign one to a person of the campaign.
        $ownerId = $isGm ? $this->resolveOwner($campaign, $data['owner_user_id'] ?? null) : $viewer->id;

        $attributes = ['user_id' => $ownerId, 'name' => trim((string) ($data['name'] ?? '')) ?: 'New character'];

        if (filled($data['ddb_url'] ?? null)) {
            $attributes = [...$attributes, ...$this->fromDdb($data['ddb_url'], $ddb, $images)];
        }

        $campaign->characters()->create($attributes);

        return back();
    }

    /**
     * A player's personal character, created from the dashboard and not attached to any campaign yet.
     * Optionally imported from a public D&D Beyond character link.
     */
    public function personalStore(Request $request, DdbClient $ddb, DdbImageStore $images)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'ddb_url' => ['nullable', 'string', 'max:300'],
        ]);

        $attributes = [
            'user_id' => $request->user()->id,
            'campaign_id' => null,
            'name' => trim((string) ($data['name'] ?? '')) ?: 'New character',
        ];

        if (filled($data['ddb_url'] ?? null)) {
            $attributes = [...$attributes, ...$this->fromDdb($data['ddb_url'], $ddb, $images)];
        }

        Character::create($attributes);

        return back();
    }

    /** Attach one of the player's own personal (unattached) characters to this campaign. */
    public function attach(Request $request, Campaign $campaign)
    {
        $viewer = $request->user();
        abort_unless($viewer->can('manage', $campaign) || $campaign->hasMember($viewer), Response::HTTP_FORBIDDEN);

        $data = $request->validate(['character_id' => ['required', 'integer']]);

        $character = Character::whereKey($data['character_id'])
            ->whereNull('campaign_id')->where('user_id', $viewer->id)->first();
        abort_if($character === null, Response::HTTP_FORBIDDEN, 'That character is not yours to attach.');

        $character->update(['campaign_id' => $campaign->id]);

        return back();
    }

    /** Remove a character from its campaign — it returns to the owner's personal roster (not deleted). */
    public function detach(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $character->update(['campaign_id' => null]);

        return back();
    }

    public function update(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'level' => ['nullable', 'integer', 'min:1', 'max:30'],
            'class' => ['nullable', 'string', 'max:120'],
            'race' => ['nullable', 'string', 'max:120'],
            'ac' => ['nullable', 'integer', 'min:0'],
            'hp' => ['nullable', 'integer', 'min:0'],
            'max_hp' => ['nullable', 'integer', 'min:0'],
            'speed' => ['nullable', 'integer', 'min:0'],
            'passive_perception' => ['nullable', 'integer', 'min:0'],
            'stats' => ['nullable', 'array'],
            'stats.str' => ['nullable', 'integer'],
            'stats.dex' => ['nullable', 'integer'],
            'stats.con' => ['nullable', 'integer'],
            'stats.int' => ['nullable', 'integer'],
            'stats.wis' => ['nullable', 'integer'],
            'stats.cha' => ['nullable', 'integer'],
            'ddb_url' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'owner_user_id' => ['nullable', 'integer'],
        ]);

        // Only the GM of a campaign character may reassign it to a different player.
        if (array_key_exists('owner_user_id', $data) && $character->campaign !== null && $request->user()->can('manage', $character->campaign)) {
            $character->user_id = $this->resolveOwner($character->campaign, $data['owner_user_id']);
        }
        unset($data['owner_user_id']);

        $character->update($data);

        return back();
    }

    /** Queue a pull of the linked D&D Beyond campaign's characters into the roster. */
    public function pullDdb(Request $request, Campaign $campaign, DdbClient $ddb)
    {
        $this->authorize('manage', $campaign);

        $world = $campaign->world;
        abort_unless($world->ddb_enabled, Response::HTTP_FORBIDDEN);

        if (blank($world->ddbCobalt()) || blank($world->ddb_campaign_id)) {
            throw ValidationException::withMessages(['ddb' => 'Link a D&D Beyond campaign on the D&D Beyond page first.']);
        }

        // Validate the token now for immediate feedback; the heavy per-character work runs on the queue.
        if ($ddb->token($world->ddbCobalt()) === null) {
            throw ValidationException::withMessages(['ddb' => 'Your D&D Beyond token was rejected. Refresh it on the D&D Beyond page.']);
        }

        dispatch(new SyncDdbCharacters($campaign->id));

        return back()->with('success', 'Character sync started — the roster will update shortly.');
    }

    /** Re-pull portrait and stats from the linked D&D Beyond character. */
    public function refresh(Request $request, Character $character, DdbClient $ddb, DdbImageStore $images)
    {
        $this->authorizeCharacter($request, $character);

        if (blank($character->ddb_url)) {
            throw ValidationException::withMessages(['ddb_url' => 'This character has no D&D Beyond link.']);
        }

        $character->update($this->fromDdb($character->ddb_url, $ddb, $images));

        return back();
    }

    public function uploadImage(Request $request, Character $character): JsonResponse
    {
        $this->authorizeCharacter($request, $character);

        // Uppy posts the portrait here and expects JSON.
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:'.implode(',', config('media.accept')), 'max:51200'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first('file') ?: 'That image could not be uploaded.'], 422);
        }

        $disk = config('media.disk');
        $file = $request->file('file');
        if (! $request->user()->canStore((int) $file->getSize())) {
            return response()->json(['message' => 'Storage limit reached — delete some media or upgrade your plan for more space.'], 422);
        }

        $media = Media::create([
            'user_id' => $request->user()->id,
            'world_id' => $character->campaign?->world_id,
            'disk' => $disk,
            'path' => $file->store('media', $disk),
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        $character->update(['image_media_id' => $media->id]);

        return response()->json(['image_url' => $media->url]);
    }

    public function destroy(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $character->delete();

        return back();
    }

    /**
     * Fetch a D&D Beyond character by link and return the attributes to store.
     *
     * @return array<string, mixed>
     */
    private function fromDdb(string $url, DdbClient $ddb, DdbImageStore $images): array
    {
        if (preg_match('/characters?\/(\d+)/i', $url, $matches) !== 1) {
            throw ValidationException::withMessages(['ddb_url' => 'That does not look like a D&D Beyond character link.']);
        }

        $raw = $ddb->character($matches[1]);
        if ($raw === null) {
            throw ValidationException::withMessages(['ddb_url' => 'Could not fetch that character. Is it set to public on D&D Beyond?']);
        }

        $profile = Ddb::characterProfile($raw, $matches[1]);

        return [
            'name' => $profile['name'],
            'ddb_character_id' => $matches[1],
            'ddb_url' => $url,
            'image_media_id' => $images->resolve($profile['image']),
            'level' => $profile['level'],
            'class' => $profile['class'],
            'race' => $profile['race'],
            'speed' => $profile['speed'],
            'passive_perception' => $profile['passive_perception'],
            'ac' => $profile['ac'],
            'hp' => $profile['hp'],
            'max_hp' => $profile['max_hp'],
            'stats' => $profile['stats'],
            'sheet' => Ddb::characterSheet($raw),
        ];
    }

    /** Restrict a GM-chosen owner to a person of this campaign; null otherwise. */
    private function resolveOwner(Campaign $campaign, mixed $userId): ?int
    {
        if ($userId === null) {
            return null;
        }

        return in_array((int) $userId, $this->peopleIds($campaign), true) ? (int) $userId : null;
    }

    /** @return list<int> */
    private function peopleIds(Campaign $campaign): array
    {
        return collect([$campaign->world->user_id])
            ->merge($campaign->members()->pluck('user_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    private function people(Campaign $campaign)
    {
        return User::whereIn('id', $this->peopleIds($campaign))
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Character $character, int $viewerId, bool $isGm): array
    {
        return [
            'id' => $character->id,
            'name' => $character->name,
            'slug' => $character->slug,
            'image_url' => $character->image?->url,
            'level' => $character->level,
            'class' => $character->class,
            'race' => $character->race,
            'speed' => $character->speed,
            'passive_perception' => $character->passive_perception,
            'ac' => $character->ac,
            'hp' => $character->hp,
            'max_hp' => $character->max_hp,
            'stats' => $character->stats,
            'notes' => $character->notes,
            'ddb_url' => $character->ddb_url,
            'ddb_unreadable' => $character->ddb_unreadable,
            'owner_id' => $character->user_id,
            'owner_name' => $character->owner?->name,
            'can_edit' => $isGm || $character->user_id === $viewerId,
        ];
    }

    /** The owner or (for campaign characters) the GM may edit a character. */
    private function authorizeCharacter(Request $request, Character $character): void
    {
        $viewer = $request->user();
        $isGm = $character->campaign !== null && $viewer->can('manage', $character->campaign);
        abort_unless($isGm || $character->user_id === $viewer->id, Response::HTTP_FORBIDDEN);
    }
}
