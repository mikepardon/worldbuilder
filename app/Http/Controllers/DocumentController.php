<?php

namespace App\Http\Controllers;

use App\Models\CampaignCompendiumItem;
use App\Models\Character;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\Map;
use App\Models\MapPin;
use App\Models\Media;
use App\Models\World;
use App\Services\AnthropicClient;
use App\Support\AiUsageContext;
use App\Support\Compendium;
use App\Support\Connections;
use App\Support\CreditWeights;
use App\Support\DocFields;
use App\Support\Relationships;
use App\Support\Secrets;
use App\Support\Sections;
use App\Support\WikiLinks;
use App\Support\WorldNav;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

class DocumentController extends Controller
{
    public function store(Request $request, World $world)
    {
        $this->authorize('editContent', $world);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'kind' => ['required', 'in:'.implode(',', Sections::KINDS)],
        ]);

        $document = $world->documents()->create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'slug' => Document::uniqueSlug($world->id, $data['kind'], $data['title']),
            'kind' => $data['kind'],
            'content' => "# {$data['title']}\n\n",
            'is_private' => $world->defaultEntryPrivate(),
        ]);

        return redirect()->route('documents.edit', $document);
    }

    public function edit(Document $document)
    {
        $this->authorize('update', $document);
        $document->load('world');

        return Inertia::render('Documents/Edit', [
            // The world nav (matches the rest of the GM workspace chrome).
            'world' => WorldNav::for($document->world),
            'campaign' => [
                'id' => $document->world->id,
                'name' => $document->world->name,
            ],
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'slug' => $document->slug,
                'card_url' => $document->card?->url,
                'banner_url' => $document->banner?->url,
                'kind' => $document->kind,
                'kindLabel' => Sections::kindLabel($document->kind),
                'content' => $document->content,
                'summary' => $document->summary,
                'data' => $document->data ?? (object) [],
                'is_private' => $document->is_private,
                'is_featured' => $document->is_featured,
                'hide_from_search' => $document->hide_from_search,
                'cover_mode' => $document->cover_mode ?? 'default',
                'template_id' => $document->template_id,
                'accent' => $document->accent,
                'publish_at' => $document->publish_at?->toIso8601String(),
                'comments_enabled' => $document->comments_enabled,
                'show_toc' => $document->show_toc,
                'related_ids' => $document->related_ids ?? [],
                'tags' => $document->tags ?? [],
            ],
            'fields' => DocFields::for($document->kind, $document->world),
            'isFormKind' => DocFields::isFormKind($document->kind),
            // The world's layout templates available for this kind (for the Settings tab picker).
            'templates' => $document->world->templates()->where('kind', $document->kind)->orderBy('name')->get(['id', 'name']),
            'isArticleKind' => $document->kind === 'article',
            'isTimeline' => $document->kind === 'timeline',
            'isBloodline' => $document->kind === 'bloodline',
            // This entry's manual connections + who links back to it, and the whole-world graph.
            'links' => $document->outgoingLinks()->where('source', 'manual')->with('toDocument:id,title,kind')->get()
                ->map(fn (DocumentLink $l) => [
                    'id' => $l->id,
                    'to' => $l->to_document_id,
                    'title' => $l->toDocument?->title,
                    'kindLabel' => $l->toDocument ? Sections::kindLabel($l->toDocument->kind) : null,
                    // The relationship phrase to show (custom label wins over the typed phrase).
                    'label' => Relationships::label($l->relationship, $l->label),
                ]),
            'relationshipOptions' => Relationships::options(),
            // Per-entry access control state for the reader (password gate + share link).
            'access' => [
                'has_password' => filled($document->access_password),
                'share_url' => $document->share_token
                    ? url("/w/{$document->world->slug}/".Sections::typeSlug($document->kind)."/{$document->slug}").'?key='.$document->share_token
                    : null,
            ],
            // The public reader page for this entry, when the world is published (public/unlisted).
            'viewUrl' => in_array($document->world->visibility, ['public', 'hidden'], true)
                ? url("/w/{$document->world->slug}/".Sections::typeSlug($document->kind)."/{$document->slug}")
                : null,
            'backlinks' => $document->incomingLinks()->with('fromDocument:id,title,kind')->get()
                ->map(fn (DocumentLink $l) => [
                    'id' => $l->id,
                    'from' => $l->from_document_id,
                    'title' => $l->fromDocument?->title,
                    'kindLabel' => $l->fromDocument ? Sections::kindLabel($l->fromDocument->kind) : null,
                    'label' => $l->label,
                    'source' => $l->source,
                ]),
            'graph' => Connections::graph($document->world),
            // Full embed data so every editor preview (article, brew, and field-kind entries) can render
            // compendium cards live, matching what the public reader resolves from the same content.
            'embeds' => $document->world->compendiumItems()->where('is_active', true)->with('image')->get()
                ->map(fn (CampaignCompendiumItem $i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'item_type' => $i->item_type,
                    'typeLabel' => Compendium::label($i->item_type),
                    'block' => $i->item_type === 'monster' ? data_get($i->fields, 'block') : null,
                    'image_url' => $i->image?->url,
                    'document' => $i->item_type === 'monster' ? null : $i->document,
                ])->values(),
            'allTags' => $this->worldTags($document->world),
            // Party characters, for @-mention autocomplete in the editor.
            'characters' => Character::whereIn('campaign_id', $document->world->campaigns()->pluck('id'))
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->map(fn ($character) => ['id' => $character->id, 'name' => $character->name, 'slug' => $character->slug]),
            'entries' => $document->world->documents()
                ->where('id', '!=', $document->id)
                ->with('card')
                ->orderBy('title')
                ->get()
                ->map(fn (Document $d) => [
                    'id' => $d->id,
                    'title' => $d->title,
                    'kind' => $d->kind,
                    'kindLabel' => Sections::kindLabel($d->kind),
                    'image' => $d->card?->url,
                ]),
            // Spells (id/name/summary) so a monster's spellcasting entries can hover-preview their spell.
            'spells' => $document->world->compendiumItems()
                ->where('item_type', 'spell')
                ->orderBy('name')
                ->get(['id', 'name', 'summary', 'document', 'fields'])
                ->map(fn (CampaignCompendiumItem $s) => ['id' => $s->id, 'name' => $s->name, 'summary' => $s->summary, 'document' => $s->document, 'fields' => $s->fields ?? []])
                ->values(),
            // Compendium entries the GM can drop into the page as embeds ({{monster=id}} etc.).
            'compendium' => $document->world->compendiumItems()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'item_type'])
                ->map(fn (CampaignCompendiumItem $i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'type' => $i->item_type,
                    'typeLabel' => Compendium::label($i->item_type),
                    'token' => '{{'.$i->item_type.'='.$i->id.'}}',
                ]),
            // A location's single map, managed in the editor's Map tab (null until one is created).
            'map' => $this->locationMap($document),
        ]);
    }

    /**
     * The map that depicts a location, shaped for the editor's Map tab. Null for non-locations or
     * locations that have no map yet.
     *
     * @return array<string, mixed>|null
     */
    protected function locationMap(Document $document): ?array
    {
        if ($document->kind !== 'location') {
            return null;
        }

        $map = Map::where('document_id', $document->id)->with(['image', 'pins.document.card', 'pins.compendiumItem.image'])->first();
        if ($map === null) {
            return null;
        }

        return [
            'id' => $map->id,
            'image_url' => $map->image?->url,
            'image_media_id' => $map->image_media_id,
            'is_private' => $map->is_private,
            'real_width' => $map->real_width,
            'distance_unit' => $map->distance_unit,
            'pins' => $map->pins->map(fn (MapPin $pin) => [
                'id' => $pin->id,
                'x' => $pin->x,
                'y' => $pin->y,
                'label' => $pin->label,
                'note' => $pin->note,
                'behavior' => $pin->behavior,
                'style' => $pin->style ?? 'marker',
                'document_id' => $pin->document_id,
                'compendium_item_id' => $pin->compendium_item_id,
                'document_title' => $pin->document?->title ?? $pin->compendiumItem?->name,
                // A token shows the linked entry's card image (or the monster's image) as its portrait.
                'image_url' => $pin->document?->card?->url ?? $pin->compendiumItem?->image?->url,
            ]),
        ];
    }

    public function update(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'content' => ['nullable', 'string'],
            'summary' => ['nullable', 'string', 'max:500'],
            'data' => ['nullable', 'array'],
            'is_private' => ['sometimes', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
            'is_featured' => ['sometimes', 'boolean'],
            'hide_from_search' => ['sometimes', 'boolean'],
            'cover_mode' => ['sometimes', 'in:default,show,hide'],
            'accent' => ['sometimes', 'nullable', 'in:'.implode(',', World::READER_THEMES)],
            'publish_at' => ['sometimes', 'nullable', 'date'],
            'comments_enabled' => ['sometimes', 'boolean'],
            'show_toc' => ['sometimes', 'boolean'],
            'related_ids' => ['sometimes', 'nullable', 'array', 'max:12'],
            'related_ids.*' => ['integer', Rule::exists('documents', 'id')->where('world_id', $document->world_id)],
            'template_id' => ['sometimes', 'nullable', 'integer', Rule::exists('world_templates', 'id')->where('world_id', $document->world_id)->where('kind', $document->kind)],
        ]);

        $document->update($data);

        // Keep the wiki-link connections in step with the content whenever it changes.
        if (array_key_exists('content', $data)) {
            WikiLinks::sync($document);
        }

        return back();
    }

    /**
     * Rename the entry's public URL slug. Kept out of update() so the editor's debounced
     * content autosave never rewrites the slug on every keystroke. The slug is normalised
     * and must stay unique within its world and kind.
     */
    public function updateSlug(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $slug = Str::slug($request->string('slug')->toString());

        $validator = Validator::make(['slug' => $slug], [
            'slug' => [
                'required', 'string', 'max:160',
                Rule::unique('documents', 'slug')
                    ->where('world_id', $document->world_id)
                    ->where('kind', $document->kind)
                    ->ignore($document->id),
            ],
        ], [
            'slug.required' => 'Enter a URL made of letters, numbers and hyphens.',
            'slug.unique' => 'Another entry of this type already uses that URL.',
        ], ['slug' => 'URL']);

        if ($validator->fails()) {
            return back()->withErrors(['slug' => $validator->errors()->first('slug')]);
        }

        $oldSlug = $document->slug;
        if ($oldSlug === $slug) {
            return back();
        }

        // Keep the old slug as an alias so existing links redirect to the new URL.
        $aliases = collect($document->slug_aliases ?? [])
            ->push($oldSlug)->unique()->reject(fn (string $alias): bool => $alias === $slug)->values()->all();

        $document->update(['slug' => $slug, 'slug_aliases' => $aliases]);

        return back();
    }

    /** Upload a per-entry image (card thumbnail or hero banner) and point the document at it. */
    public function image(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'type' => ['required', 'in:card,banner'],
            'file' => ['required', 'file', 'mimes:'.implode(',', config('media.accept')), 'max:'.config('media.max_size')],
        ]);

        $user = $request->user();
        $file = $request->file('file');

        if (! $user->canStore((int) $file->getSize())) {
            return back()->withErrors(['file' => 'Storage limit reached — free up space or upgrade your plan for more.']);
        }

        $disk = config('media.disk');
        $path = $file->store('media', $disk);

        $media = Media::create([
            'user_id' => $user->id,
            'world_id' => $document->world_id,
            'disk' => $disk,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        // 'type' is whitelisted to card|banner, so the column name is not user-controlled.
        $document->update(["{$data['type']}_media_id" => $media->id]);

        return back();
    }

    /** Remove a per-entry image (the media item stays in the library). */
    public function clearImage(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $data = $request->validate(['type' => ['required', 'in:card,banner']]);

        $document->update(["{$data['type']}_media_id" => null]);

        return back();
    }

    /**
     * Turn a session's rough notes into a polished recap. The original session is left untouched;
     * a new, linked "article" document is created for the write-up and the editor opens it.
     */
    public function writeUp(Request $request, Document $document, AnthropicClient $ai)
    {
        $this->authorize('update', $document);

        // Explicit JSON validation: this XHR endpoint is not under api/*, so the framework would
        // otherwise try a web redirect on failure (see shouldRenderJsonWhen in bootstrap/app.php).
        $validator = Validator::make($request->all(), [
            'notes' => ['required', 'string', 'max:8000'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first('notes')], 422);
        }
        $notes = (string) $validator->validated()['notes'];

        if (! $ai->configured()) {
            return response()->json(['message' => "AI isn't set up on this server yet."], 422);
        }

        $user = $request->user();
        $creditCost = CreditWeights::forFeature('session_writeup');
        if (! $user->canSpendAiCredits($creditCost)) {
            return response()->json([
                'message' => 'You’re out of AI credits for today — they reset daily. Top up or upgrade for more.',
                'outOfCredits' => true,
            ], 402);
        }

        $world = $document->world;
        $system = "You are a worldbuilding assistant for a tabletop RPG campaign called \"{$world->name}\"."
            .($world->setting ? " The setting: {$world->setting}." : '')
            .' Turn the GM\'s rough session notes into a polished, well-structured recap article in Markdown.'
            .' Open with a level-1 heading, use sub-headings, and stay evocative and consistent with the world.'
            .' Respond with ONLY the article.';

        try {
            $article = $ai->chat(
                $system,
                [['role' => 'user', 'content' => "Session: {$document->title}\n\nRough notes:\n\n{$notes}"]],
                1500,
                new AiUsageContext('session_writeup', $world->id, $user->id),
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $user->spendAiCredits($creditCost);

        $title = "{$document->title} — Recap";
        $recap = DB::transaction(function () use ($world, $document, $request, $title, $article) {
            $recap = $world->documents()->create([
                'user_id' => $request->user()->id,
                'title' => $title,
                'slug' => Document::uniqueSlug($world->id, 'article', $title),
                'kind' => 'article',
                'content' => $article,
                'is_private' => $document->is_private,
            ]);

            // Link the session to its recap so they stay connected.
            $document->outgoingLinks()->firstOrCreate([
                'to_document_id' => $recap->id,
                'label' => 'Session recap',
                'source' => 'manual',
            ], ['world_id' => $world->id]);

            WikiLinks::sync($recap);

            return $recap;
        });

        return response()->json(['redirect' => route('documents.edit', $recap)]);
    }

    /** Add a manual connection from this entry to another entry in the same world. */
    public function linkStore(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'to_document_id' => [
                'required',
                Rule::exists('documents', 'id')->where('world_id', $document->world_id),
                Rule::notIn([$document->id]),
            ],
            'relationship' => ['nullable', 'string', 'max:40'],
            'label' => ['nullable', 'string', 'max:60'],
        ]);

        // updateOrCreate (not firstOrCreate) so re-adding an existing connection updates its type.
        $document->outgoingLinks()->updateOrCreate([
            'to_document_id' => $data['to_document_id'],
            'source' => 'manual',
        ], [
            'world_id' => $document->world_id,
            'relationship' => Relationships::normalise($data['relationship'] ?? null),
            'label' => $data['label'] ?? null,
        ]);

        return back();
    }

    /** Set or clear the reader password gate for an entry. */
    public function access(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'password' => ['nullable', 'string', 'max:100'],
        ]);

        // The hashed cast hashes a non-empty value on save; a blank value clears the gate.
        $document->update(['access_password' => filled($data['password'] ?? null) ? $data['password'] : null]);

        return back()->with('success', filled($data['password'] ?? null) ? 'Password set.' : 'Password removed.');
    }

    /** Create (or regenerate) a secret share link for an entry. */
    public function share(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $document->update(['share_token' => Str::random(40)]);

        return back()->with('success', 'Share link created.');
    }

    /** Revoke an entry's share link. */
    public function unshare(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $document->update(['share_token' => null]);

        return back()->with('success', 'Share link revoked.');
    }

    /** Remove a manual connection. */
    public function linkDestroy(DocumentLink $link)
    {
        $this->authorize('update', $link->fromDocument);
        abort_unless($link->source === 'manual', 403, 'Wiki links are managed from the article text.');

        $link->delete();

        return back();
    }

    /** Distinct, sorted tags used across a world's documents (for filters + autocomplete). */
    protected function worldTags(World $world): array
    {
        return $world->documents()->pluck('tags')
            ->flatten()->filter()->unique()->sort()->values()->all();
    }

    public function destroy(Document $document)
    {
        $this->authorize('delete', $document);
        $world = $document->world;
        $document->delete();

        return redirect()->route('worlds.show', $world);
    }

    /** Reveal a GM-only secret block to players by unwrapping the Nth {{secret}} block. */
    public function reveal(Request $request, Document $document)
    {
        $this->authorize('update', $document);
        $index = (int) $request->input('index', 0);
        $document->update(['content' => Secrets::reveal($document->content, $index)]);

        return back();
    }
}
