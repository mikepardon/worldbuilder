<?php

use App\Http\Controllers\Admin\AiUsageController as AdminAiUsage;
use App\Http\Controllers\Admin\AttributesController as AdminAttributes;
use App\Http\Controllers\Admin\BillingController as AdminBilling;
use App\Http\Controllers\Admin\CampaignsController as AdminCampaigns;
use App\Http\Controllers\Admin\CompendiumController as AdminCompendium;
use App\Http\Controllers\Admin\DashboardController as AdminDashboard;
use App\Http\Controllers\Admin\UsersController as AdminUsers;
use App\Http\Controllers\AiController;
use App\Http\Controllers\ArticleNoteController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\CompendiumController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DdbController;
use App\Http\Controllers\DeepgramWebhookController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\GeneratorController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LlmsController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\MapPinController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MembersController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicRecapController;
use App\Http\Controllers\PublicWorldController;
use App\Http\Controllers\RecapController;
use App\Http\Controllers\RecapEntityController;
use App\Http\Controllers\RollTableController;
use App\Http\Controllers\RoomChatController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomDrawingController;
use App\Http\Controllers\RoomHandoutController;
use App\Http\Controllers\RoomNoteController;
use App\Http\Controllers\RoomSceneController;
use App\Http\Controllers\RoomSearchController;
use App\Http\Controllers\RoomTemplateController;
use App\Http\Controllers\RoomTokenController;
use App\Http\Controllers\RoomTrackerController;
use App\Http\Controllers\SandboxController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SessionNoteController;
use App\Http\Controllers\SessionUploadController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\UserNoteController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WorldAttributeController;
use App\Http\Controllers\WorldBuildController;
use App\Http\Controllers\WorldController;
use App\Http\Controllers\WorldDomainController;
use App\Http\Controllers\WorldMemberController;
use App\Http\Controllers\WorldSessionController;
use App\Http\Controllers\WorldBlockController;
use App\Http\Controllers\WorldTemplateController;
use App\Http\Middleware\EnforceReaderPassword;
use Illuminate\Support\Facades\Route;

// Stripe webhooks (public, no session/CSRF — verified by signature in the controller).
Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

// Deepgram delivers finished transcripts here (public, no session/CSRF). The per-recap URL is signed, so
// the `signed` middleware authenticates the caller; `relative` lets it validate behind a tunnel/proxy.
Route::post('/webhooks/deepgram/{recap}', DeepgramWebhookController::class)
    ->middleware('signed:relative')
    ->name('recaps.deepgram.webhook');

// Public shared recap — read-only, reachable by anyone with the link.
Route::get('/recap/{token}', [PublicRecapController::class, 'show'])->name('public.recap');

// Marketing / landing
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/how-it-works', [MarketingController::class, 'howItWorks'])->name('marketing.how');
Route::get('/features', [MarketingController::class, 'features'])->name('marketing.features');
Route::get('/pricing', [MarketingController::class, 'pricing'])->name('marketing.pricing');
Route::get('/faq', [MarketingController::class, 'faq'])->name('marketing.faq');
Route::get('/llms.txt', LlmsController::class)->name('llms');

// World reader password gate — kept outside the reader group so the enforcing middleware never blocks it.
Route::get('/w/{world:slug}/unlock', [PublicWorldController::class, 'worldLockScreen'])->name('public.world.locked');
Route::post('/w/{world:slug}/unlock', [PublicWorldController::class, 'unlockWorld'])->name('public.world.unlock');

// Public world reader — /w/{world-slug}/{type}/{entry-slug}
Route::prefix('w/{world:slug}')->name('public.')->middleware(EnforceReaderPassword::class)->group(function () {
    Route::get('/', [PublicWorldController::class, 'show'])->name('world');
    Route::get('/search/query', [PublicWorldController::class, 'search'])->name('search');
    Route::get('/random', [PublicWorldController::class, 'random'])->name('random');
    Route::get('/web', [PublicWorldController::class, 'web'])->name('web');
    Route::get('/llms.txt', [PublicWorldController::class, 'llms'])->name('llms');
    Route::get('/feed', [PublicWorldController::class, 'feed'])->name('feed');
    Route::get('/s/{section}', [PublicWorldController::class, 'section'])->name('section');
    Route::get('/maps', [PublicWorldController::class, 'maps'])->name('maps');
    Route::get('/maps/{map:slug}', [PublicWorldController::class, 'map'])->scopeBindings()->name('map');
    Route::get('/compendium', [PublicWorldController::class, 'compendium'])->name('compendium');
    Route::get('/compendium/{slug}', [PublicWorldController::class, 'compendiumItem'])->name('compendium.item');
    Route::get('/campaigns', [PublicWorldController::class, 'campaigns'])->name('campaigns');
    Route::get('/campaigns/{campaign:slug}', [PublicWorldController::class, 'campaign'])->scopeBindings()->name('campaign');
    Route::get('/campaigns/{campaign:slug}/sessions', [PublicWorldController::class, 'campaignSessions'])->scopeBindings()->name('campaign.sessions');
    Route::get('/campaigns/{campaign:slug}/sessions/{session:slug}', [PublicWorldController::class, 'session'])->scopeBindings()->name('campaign.session');
    Route::get('/campaigns/{campaign:slug}/schedule', [PublicWorldController::class, 'campaignSchedule'])->scopeBindings()->name('campaign.schedule');
    Route::get('/campaigns/{campaign:slug}/schedule.ics', [PublicWorldController::class, 'campaignIcs'])->scopeBindings()->name('campaign.ics');
    Route::get('/campaigns/{campaign:slug}/characters', [PublicWorldController::class, 'campaignCharacters'])->scopeBindings()->name('campaign.characters');
    Route::get('/campaigns/{campaign:slug}/characters/{character:slug}', [PublicWorldController::class, 'campaignCharacter'])->scopeBindings()->name('campaign.character');
    Route::get('/{type}/{slug}/embed', [PublicWorldController::class, 'embed'])->name('embed');
    Route::post('/{type}/{slug}/unlock', [PublicWorldController::class, 'unlock'])->name('article.unlock');
    Route::get('/{type}/{slug}', [PublicWorldController::class, 'article'])->name('article');
});

// Authenticated GM workspace
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // The seeded sandbox world: view it (player perspective) or copy it into your own world.
    Route::get('/sandbox', [SandboxController::class, 'view'])->name('sandbox.view');
    Route::post('/sandbox/clone', [SandboxController::class, 'clone'])->name('sandbox.clone');

    // A player's personal character (dashboard) — not attached to any campaign; may import from D&D Beyond.
    Route::post('/characters', [CharacterController::class, 'personalStore'])->name('characters.personal.store');

    // Worlds — the setting (lore, people, locations, timelines, compendium, maps).
    Route::resource('worlds', WorldController::class)->only(['create', 'store', 'show', 'update', 'destroy']);
    Route::get('/worlds/{world}/settings', [WorldController::class, 'settings'])->name('worlds.settings');
    Route::get('/worlds/{world}/entries', [WorldController::class, 'entries'])->name('worlds.entries');
    // Build a world's wiki entries from its seed notes in the background, and poll that build's progress.
    Route::post('/worlds/{world}/build', [WorldBuildController::class, 'store'])->name('worlds.build.store');
    Route::get('/worlds/{world}/build/status', [WorldBuildController::class, 'status'])->name('worlds.build.status');
    Route::put('/worlds/{world}/cover', [WorldController::class, 'cover'])->name('worlds.cover');
    Route::post('/worlds/{world}/branding', [WorldController::class, 'branding'])->name('worlds.branding');
    Route::delete('/worlds/{world}/branding', [WorldController::class, 'clearBranding'])->name('worlds.branding.clear');
    Route::post('/worlds/{world}/section-image', [WorldController::class, 'sectionImage'])->name('worlds.section-image');
    Route::delete('/worlds/{world}/section-image', [WorldController::class, 'clearSectionImage'])->name('worlds.section-image.clear');
    Route::get('/worlds/{world}/web', [WorldController::class, 'web'])->name('worlds.web');

    // GM scheduling calendar across the world's campaigns.
    Route::get('/worlds/{world}/schedule', [ScheduleController::class, 'index'])->name('schedule.index');
    Route::post('/worlds/{world}/schedule', [ScheduleController::class, 'store'])->name('schedule.store');
    Route::put('/schedule-events/{event}', [ScheduleController::class, 'update'])->name('schedule.update');
    Route::delete('/schedule-events/{event}', [ScheduleController::class, 'destroy'])->name('schedule.destroy');
    Route::post('/schedule-events/{event}/rsvp', [ScheduleController::class, 'respond'])->name('schedule.respond');

    // Custom domain setup + verification (Basic/Pro entitlement).
    Route::get('/worlds/{world}/domain', [WorldDomainController::class, 'edit'])->name('worlds.domain.edit');
    Route::put('/worlds/{world}/domain', [WorldDomainController::class, 'update'])->name('worlds.domain.update');
    Route::post('/worlds/{world}/domain/verify', [WorldDomainController::class, 'verify'])->name('worlds.domain.verify');
    Route::delete('/worlds/{world}/domain', [WorldDomainController::class, 'destroy'])->name('worlds.domain.destroy');

    // Co-authors (owner-only).
    Route::get('/worlds/{world}/attributes', [WorldAttributeController::class, 'index'])->name('worlds.attributes.index');
    Route::post('/worlds/{world}/attributes', [WorldAttributeController::class, 'store'])->name('worlds.attributes.store');
    Route::put('/worlds/{world}/attributes/order', [WorldAttributeController::class, 'reorder'])->name('worlds.attributes.reorder');
    Route::put('/world-attributes/{attribute}', [WorldAttributeController::class, 'update'])->name('worlds.attributes.update');
    Route::delete('/world-attributes/{attribute}', [WorldAttributeController::class, 'destroy'])->name('worlds.attributes.destroy');

    Route::get('/worlds/{world}/templates', [WorldTemplateController::class, 'index'])->name('worlds.templates.index');
    Route::get('/worlds/{world}/templates/create', [WorldTemplateController::class, 'create'])->name('worlds.templates.create');
    Route::get('/worlds/{world}/templates/home', [WorldTemplateController::class, 'home'])->name('worlds.templates.home');
    Route::get('/worlds/{world}/templates/preview/{document}', [WorldTemplateController::class, 'preview'])->name('worlds.templates.preview');
    Route::post('/worlds/{world}/templates', [WorldTemplateController::class, 'store'])->name('worlds.templates.store');
    Route::post('/worlds/{world}/templates/import', [WorldTemplateController::class, 'import'])->name('worlds.templates.import');
    Route::get('/world-templates/{template}/export', [WorldTemplateController::class, 'export'])->name('worlds.templates.export');
    Route::get('/world-templates/{template}/edit', [WorldTemplateController::class, 'edit'])->name('worlds.templates.edit');
    Route::put('/world-templates/{template}', [WorldTemplateController::class, 'update'])->name('worlds.templates.update');
    Route::delete('/world-templates/{template}', [WorldTemplateController::class, 'destroy'])->name('worlds.templates.destroy');

    Route::get('/worlds/{world}/blocks/create', [WorldBlockController::class, 'create'])->name('worlds.blocks.create');
    Route::post('/worlds/{world}/blocks', [WorldBlockController::class, 'store'])->name('worlds.blocks.store');
    Route::post('/worlds/{world}/blocks/import', [WorldBlockController::class, 'import'])->name('worlds.blocks.import');
    Route::get('/world-blocks/{block}/export', [WorldBlockController::class, 'export'])->name('worlds.blocks.export');
    Route::get('/world-blocks/{block}/edit', [WorldBlockController::class, 'edit'])->name('worlds.blocks.edit');
    Route::put('/world-blocks/{block}', [WorldBlockController::class, 'update'])->name('worlds.blocks.update');
    Route::delete('/world-blocks/{block}', [WorldBlockController::class, 'destroy'])->name('worlds.blocks.destroy');

    // Rollable random tables (encounters, loot, weather…).
    Route::get('/worlds/{world}/tables', [RollTableController::class, 'index'])->name('tables.index');
    Route::post('/worlds/{world}/tables', [RollTableController::class, 'store'])->name('tables.store');
    Route::put('/roll-tables/{rollTable}', [RollTableController::class, 'update'])->name('tables.update');
    Route::delete('/roll-tables/{rollTable}', [RollTableController::class, 'destroy'])->name('tables.destroy');
    Route::post('/worlds/{world}/tables/ai', [RollTableController::class, 'generate'])->name('tables.generate');

    Route::get('/worlds/{world}/webhooks', [WebhookController::class, 'index'])->name('worlds.webhooks.index');
    Route::post('/worlds/{world}/webhooks', [WebhookController::class, 'store'])->name('worlds.webhooks.store');
    Route::put('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
    Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');

    Route::get('/worlds/{world}/members', [WorldMemberController::class, 'index'])->name('worlds.members.index');
    Route::post('/worlds/{world}/members', [WorldMemberController::class, 'store'])->name('worlds.members.store');
    Route::put('/worlds/{world}/members/{member}', [WorldMemberController::class, 'update'])->name('worlds.members.update');
    Route::delete('/worlds/{world}/members/{member}', [WorldMemberController::class, 'destroy'])->name('worlds.members.destroy');
    Route::get('/worlds/{world}/search', [SearchController::class, 'index'])->name('search.index');
    Route::get('/worlds/{world}/search/query', [SearchController::class, 'query'])->name('search.query');

    // Custom in-world calendars.
    Route::get('/worlds/{world}/calendars', [CalendarController::class, 'index'])->name('calendars.index');
    Route::post('/worlds/{world}/calendars', [CalendarController::class, 'store'])->name('calendars.store');
    Route::put('/calendars/{calendar}', [CalendarController::class, 'update'])->name('calendars.update');
    Route::delete('/calendars/{calendar}', [CalendarController::class, 'destroy'])->name('calendars.destroy');
    Route::post('/calendars/{calendar}/events', [CalendarController::class, 'eventStore'])->name('calendars.events.store');
    Route::delete('/calendar-events/{event}', [CalendarController::class, 'eventDestroy'])->name('calendars.events.destroy');

    // AI content generators (names, NPCs, places, encounters, hooks).
    Route::get('/worlds/{world}/generators', [GeneratorController::class, 'index'])->name('generators.index');
    Route::post('/worlds/{world}/generators', [GeneratorController::class, 'run'])->name('generators.run');
    Route::post('/worlds/{world}/generators/create', [GeneratorController::class, 'create'])->name('generators.create');
    Route::post('/worlds/{world}/generators/statblock', [GeneratorController::class, 'importStatblock'])->name('generators.statblock');
    Route::get('/worlds/{world}/generators/batches/{batch}', [GeneratorController::class, 'batchShow'])->name('generators.batches.show');
    Route::put('/worlds/{world}/generators/batches/{batch}', [GeneratorController::class, 'batchUpdate'])->name('generators.batches.update');
    Route::delete('/worlds/{world}/generators/batches/{batch}', [GeneratorController::class, 'batchDestroy'])->name('generators.batches.destroy');

    // World media library
    Route::get('/worlds/{world}/media', [MediaController::class, 'index'])->name('media.index');
    Route::post('/worlds/{world}/media', [MediaController::class, 'store'])->name('media.store');
    Route::put('/media/{media}', [MediaController::class, 'update'])->name('media.update');
    Route::delete('/media/{media}', [MediaController::class, 'destroy'])->name('media.destroy');

    // Documents (world content)
    Route::post('/worlds/{world}/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/{document}/edit', [DocumentController::class, 'edit'])->name('documents.edit');
    Route::put('/documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::put('/documents/{document}/slug', [DocumentController::class, 'updateSlug'])->name('documents.slug');
    Route::post('/documents/{document}/image', [DocumentController::class, 'image'])->name('documents.image');
    Route::delete('/documents/{document}/image', [DocumentController::class, 'clearImage'])->name('documents.image.clear');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::post('/documents/{document}/ai', [AiController::class, 'ask'])->name('documents.ai');
    Route::post('/documents/{document}/draft', [AiController::class, 'draft'])->name('documents.draft');
    Route::post('/documents/{document}/ai/bloodline', [AiController::class, 'bloodline'])->name('documents.ai.bloodline');
    Route::post('/documents/{document}/write-up', [DocumentController::class, 'writeUp'])->name('documents.writeup');
    Route::put('/documents/{document}/access', [DocumentController::class, 'access'])->name('documents.access');
    Route::post('/documents/{document}/share', [DocumentController::class, 'share'])->name('documents.share');
    Route::delete('/documents/{document}/share', [DocumentController::class, 'unshare'])->name('documents.unshare');
    Route::post('/documents/{document}/links', [DocumentController::class, 'linkStore'])->name('documents.links.store');
    Route::delete('/document-links/{link}', [DocumentController::class, 'linkDestroy'])->name('documents.links.destroy');

    // Maps + pins
    Route::post('/worlds/{world}/maps', [MapController::class, 'store'])->name('maps.store');
    Route::put('/worlds/{world}/maps/{map}', [MapController::class, 'update'])->scopeBindings()->name('maps.update');
    Route::delete('/worlds/{world}/maps/{map}', [MapController::class, 'destroy'])->scopeBindings()->name('maps.destroy');
    Route::post('/worlds/{world}/maps/{map}/image', [MapController::class, 'uploadImage'])->scopeBindings()->name('maps.image');
    Route::post('/maps/{map}/pins', [MapPinController::class, 'store'])->name('map-pins.store');
    Route::put('/map-pins/{pin}', [MapPinController::class, 'update'])->name('map-pins.update');
    Route::delete('/map-pins/{pin}', [MapPinController::class, 'destroy'])->name('map-pins.destroy');

    // Compendium (world)
    Route::get('/worlds/{world}/compendium', [CompendiumController::class, 'index'])->name('compendium.index');
    Route::post('/worlds/{world}/compendium', [CompendiumController::class, 'store'])->name('compendium.store');
    Route::get('/worlds/{world}/compendium/browse', [CompendiumController::class, 'browse'])->name('compendium.browse');
    Route::post('/worlds/{world}/compendium/import', [CompendiumController::class, 'import'])->name('compendium.import');
    Route::post('/worlds/{world}/compendium/critterdb', [CompendiumController::class, 'critterDb'])->name('compendium.critterdb');
    Route::get('/compendium/{item}/edit', [CompendiumController::class, 'edit'])->name('compendium.edit');
    Route::put('/compendium/{item}', [CompendiumController::class, 'update'])->name('compendium.update');
    Route::post('/compendium/{item}/clone', [CompendiumController::class, 'clone'])->name('compendium.clone');
    Route::post('/compendium/{item}/draft', [CompendiumController::class, 'draft'])->name('compendium.draft');
    Route::post('/compendium/{item}/rebuild', [CompendiumController::class, 'rebuild'])->name('compendium.rebuild');
    Route::delete('/compendium/{item}', [CompendiumController::class, 'destroy'])->name('compendium.destroy');

    // D&D Beyond (world)
    Route::get('/worlds/{world}/ddb', [DdbController::class, 'settings'])->name('ddb.settings');
    Route::put('/worlds/{world}/ddb', [DdbController::class, 'saveSettings'])->name('ddb.settings.save');
    Route::post('/worlds/{world}/ddb/campaigns', [DdbController::class, 'fetchCampaigns'])->name('ddb.campaigns');
    Route::get('/worlds/{world}/ddb/import', [DdbController::class, 'importPage'])->name('ddb.import');
    Route::post('/worlds/{world}/ddb/import', [DdbController::class, 'startImport'])->name('ddb.import.start');

    // Campaigns — a playthrough inside a world.
    Route::get('/worlds/{world}/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::post('/worlds/{world}/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::get('/worlds/{world}/campaigns/{campaign}', [CampaignController::class, 'show'])->scopeBindings()->name('campaigns.show');
    Route::get('/worlds/{world}/campaigns/{campaign}/settings', [CampaignController::class, 'settings'])->scopeBindings()->name('campaigns.settings');
    Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
    Route::put('/campaigns/{campaign}/recap-guidance', [CampaignController::class, 'updateRecapGuidance'])->name('campaigns.recap-guidance');
    Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');

    // Sessions (campaign)
    Route::get('/worlds/{world}/sessions', [WorldSessionController::class, 'index'])->name('worlds.sessions');
    Route::post('/campaigns/{campaign}/sessions', [SessionController::class, 'store'])->name('sessions.store');
    Route::put('/sessions/{session}/details', [SessionController::class, 'details'])->name('sessions.details');
    Route::get('/sessions/{session}/view', [SessionController::class, 'view'])->name('sessions.view');
    Route::get('/sessions/{session}/edit', [SessionController::class, 'edit'])->name('sessions.edit');
    Route::put('/sessions/{session}', [SessionController::class, 'update'])->name('sessions.update');
    Route::post('/sessions/{session}/ai', [SessionController::class, 'ask'])->name('sessions.ai');
    Route::delete('/sessions/{session}', [SessionController::class, 'destroy'])->name('sessions.destroy');

    // Session recap: the post-play analysis. Audio uploads direct-to-S3 via the multipart signing
    // endpoints, then `store` records it and queues the analysis; the recap page polls `status`.
    Route::get('/sessions/{session}/recap', [RecapController::class, 'show'])->name('sessions.recap.show');
    Route::post('/sessions/{session}/recap', [RecapController::class, 'store'])->name('sessions.recap.store');
    Route::post('/sessions/{session}/recap/retry', [RecapController::class, 'retry'])->name('sessions.recap.retry');
    Route::post('/sessions/{session}/recap/reanalyse', [RecapController::class, 'reanalyse'])->name('sessions.recap.reanalyse');
    Route::get('/sessions/{session}/recap/status', [RecapController::class, 'status'])->name('sessions.recap.status');
    Route::put('/sessions/{session}/recap/content', [RecapController::class, 'updateContent'])->name('sessions.recap.content');
    Route::put('/sessions/{session}/recap/rating', [RecapController::class, 'rate'])->name('sessions.recap.rate');
    Route::post('/sessions/{session}/recap/publish', [RecapController::class, 'publish'])->name('sessions.recap.publish');
    Route::post('/sessions/{session}/recap/share', [RecapController::class, 'share'])->name('sessions.recap.share');
    Route::delete('/sessions/{session}/recap/share', [RecapController::class, 'unshare'])->name('sessions.recap.unshare');
    Route::delete('/sessions/{session}/recap', [RecapController::class, 'destroy'])->name('sessions.recap.destroy');

    // Recap entities: reconciling extracted people/places/etc. against the world (link, create, edit).
    Route::get('/recap-entities/{recapEntity}/candidates', [RecapEntityController::class, 'candidates'])->name('recap.entities.candidates');
    Route::put('/recap-entities/{recapEntity}', [RecapEntityController::class, 'update'])->name('recap.entities.update');
    Route::post('/recap-entities/{recapEntity}/link', [RecapEntityController::class, 'link'])->name('recap.entities.link');
    Route::post('/recap-entities/{recapEntity}/create', [RecapEntityController::class, 'create'])->name('recap.entities.create');
    Route::post('/recap-entities/{recapEntity}/unlink', [RecapEntityController::class, 'unlink'])->name('recap.entities.unlink');
    Route::post('/recap-entities/{recapEntity}/dismiss', [RecapEntityController::class, 'dismiss'])->name('recap.entities.dismiss');
    Route::post('/sessions/{session}/uploads', [SessionUploadController::class, 'create'])->name('sessions.uploads.create');
    Route::post('/sessions/{session}/uploads/sign', [SessionUploadController::class, 'sign'])->name('sessions.uploads.sign');
    Route::post('/sessions/{session}/uploads/list', [SessionUploadController::class, 'list'])->name('sessions.uploads.list');
    Route::post('/sessions/{session}/uploads/complete', [SessionUploadController::class, 'complete'])->name('sessions.uploads.complete');
    Route::post('/sessions/{session}/uploads/abort', [SessionUploadController::class, 'abort'])->name('sessions.uploads.abort');

    // Characters (campaign roster)
    Route::get('/campaigns/{campaign}/characters', [CharacterController::class, 'index'])->name('characters.index');
    Route::post('/campaigns/{campaign}/characters', [CharacterController::class, 'store'])->name('characters.store');
    Route::post('/campaigns/{campaign}/characters/pull', [CharacterController::class, 'pullDdb'])->name('characters.pull');
    Route::post('/campaigns/{campaign}/characters/attach', [CharacterController::class, 'attach'])->name('characters.attach');
    Route::put('/characters/{character}/detach', [CharacterController::class, 'detach'])->name('characters.detach');
    Route::put('/characters/{character}', [CharacterController::class, 'update'])->name('characters.update');
    Route::post('/characters/{character}/refresh', [CharacterController::class, 'refresh'])->name('characters.refresh');
    Route::post('/characters/{character}/image', [CharacterController::class, 'uploadImage'])->name('characters.image');
    Route::delete('/characters/{character}', [CharacterController::class, 'destroy'])->name('characters.destroy');

    // Players, invites, membership (campaign)
    Route::get('/campaigns/{campaign}/players', [MembersController::class, 'index'])->name('players.index');
    Route::post('/campaigns/{campaign}/invites', [MembersController::class, 'invite'])->name('invites.store');
    Route::delete('/invites/{invite}', [MembersController::class, 'revokeInvite'])->name('invites.revoke');
    Route::delete('/members/{member}', [MembersController::class, 'removeMember'])->name('members.remove');

    Route::get('/join/{code}', [MembersController::class, 'show'])->name('join.show');
    Route::post('/join/{code}', [MembersController::class, 'join'])->name('join.store');
});

// Reader interactions (available to any signed-in reader/GM)
Route::middleware('auth')->group(function () {
    Route::post('/documents/{document}/notes', [ArticleNoteController::class, 'store'])->name('notes.store');
    Route::patch('/notes/{note}/share', [ArticleNoteController::class, 'share'])->name('notes.share');
    Route::delete('/notes/{note}', [ArticleNoteController::class, 'destroy'])->name('notes.destroy');

    // Personal player notes on a session recap (written from the public reader).
    Route::post('/sessions/{session}/notes', [SessionNoteController::class, 'store'])->name('session-notes.store');
    Route::delete('/session-notes/{note}', [SessionNoteController::class, 'destroy'])->name('session-notes.destroy');
    Route::post('/documents/{document}/reveal', [DocumentController::class, 'reveal'])->name('documents.reveal');

    // Personal dashboard notes.
    Route::post('/user-notes', [UserNoteController::class, 'store'])->name('user-notes.store');
    Route::put('/user-notes/{userNote}', [UserNoteController::class, 'update'])->name('user-notes.update');
    Route::delete('/user-notes/{userNote}', [UserNoteController::class, 'destroy'])->name('user-notes.destroy');
});

// Battle rooms (inside a campaign) — any signed-in user can join by code; management is authorised in the controller.
Route::middleware('auth')->group(function () {
    Route::get('/campaigns/{campaign}/rooms', [RoomController::class, 'index'])->name('rooms.index');
    Route::post('/campaigns/{campaign}/rooms', [RoomController::class, 'store'])->name('rooms.store');
    Route::get('/rooms/join/{code}', [RoomController::class, 'joinShow'])->name('rooms.join.show');
    Route::post('/rooms/join/{code}', [RoomController::class, 'join'])->name('rooms.join');
    Route::get('/campaigns/{campaign}/rooms/{room}', [RoomController::class, 'show'])->scopeBindings()->name('rooms.show');
    Route::put('/rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
    Route::delete('/rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');
    Route::post('/rooms/{room}/image', [RoomController::class, 'uploadImage'])->name('rooms.image');
    Route::get('/rooms/{room}/map-options', [RoomController::class, 'mapOptions'])->name('rooms.map-options');
    Route::post('/rooms/{room}/chat', [RoomChatController::class, 'store'])->name('rooms.chat');
    Route::delete('/rooms/{room}/members/{user}', [RoomController::class, 'kick'])->name('rooms.kick');
    Route::post('/rooms/{room}/journal', [RoomNoteController::class, 'store'])->name('rooms.journal.store');
    Route::delete('/room-notes/{roomNote}', [RoomNoteController::class, 'destroy'])->name('rooms.journal.destroy');
    Route::post('/rooms/{room}/tracker', [RoomTrackerController::class, 'store'])->name('rooms.tracker');
    Route::post('/rooms/{room}/scenes', [RoomSceneController::class, 'store'])->name('rooms.scenes.store');
    Route::put('/room-scenes/{roomScene}', [RoomSceneController::class, 'update'])->name('rooms.scenes.update');
    Route::post('/room-scenes/{roomScene}/activate', [RoomSceneController::class, 'activate'])->name('rooms.scenes.activate');
    Route::post('/room-scenes/{roomScene}/import-tokens', [RoomSceneController::class, 'importTokens'])->name('rooms.scenes.import-tokens');
    Route::delete('/room-scenes/{roomScene}', [RoomSceneController::class, 'destroy'])->name('rooms.scenes.destroy');
    Route::post('/rooms/{room}/drawings', [RoomDrawingController::class, 'store'])->name('rooms.drawings.store');
    Route::delete('/rooms/{room}/drawings', [RoomDrawingController::class, 'clear'])->name('rooms.drawings.clear');
    Route::delete('/room-drawings/{roomDrawing}', [RoomDrawingController::class, 'destroy'])->name('rooms.drawings.destroy');
    Route::post('/rooms/{room}/templates', [RoomTemplateController::class, 'store'])->name('rooms.templates.store');
    Route::delete('/rooms/{room}/templates', [RoomTemplateController::class, 'clear'])->name('rooms.templates.clear');
    Route::put('/room-templates/{roomTemplate}', [RoomTemplateController::class, 'update'])->name('rooms.templates.update');
    Route::delete('/room-templates/{roomTemplate}', [RoomTemplateController::class, 'destroy'])->name('rooms.templates.destroy');
    Route::post('/rooms/{room}/handouts', [RoomHandoutController::class, 'store'])->name('rooms.handouts.store');
    Route::delete('/room-handouts/{roomHandout}', [RoomHandoutController::class, 'destroy'])->name('rooms.handouts.destroy');
    Route::get('/rooms/{room}/search', RoomSearchController::class)->name('rooms.search');
    Route::post('/rooms/{room}/tokens', [RoomTokenController::class, 'store'])->name('room-tokens.store');
    Route::post('/rooms/{room}/tokens/ddb', [RoomTokenController::class, 'importDdb'])->name('room-tokens.ddb');
    Route::post('/room-tokens/{token}/image', [RoomTokenController::class, 'uploadImage'])->name('room-tokens.image');
    Route::post('/room-tokens/{token}/hit-dice', [RoomTokenController::class, 'spendHitDice'])->name('room-tokens.hit-dice');
    Route::put('/room-messages/{message}/concentration', [RoomTokenController::class, 'concentrationSave'])->name('room-messages.concentration');
    Route::put('/room-tokens/{token}', [RoomTokenController::class, 'update'])->name('room-tokens.update');
    Route::delete('/room-tokens/{token}', [RoomTokenController::class, 'destroy'])->name('room-tokens.destroy');
});

// Profile (Breeze)
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [ProfileController::class, 'avatar'])->name('profile.avatar');
    Route::delete('/profile/avatar', [ProfileController::class, 'removeAvatar'])->name('profile.avatar.destroy');
    Route::put('/profile/ddb', [ProfileController::class, 'ddb'])->name('profile.ddb');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // A player's own billing: current plan, usage, and changing plan.
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::post('/billing/cancel-downgrade', [BillingController::class, 'cancelDowngrade'])->name('billing.cancel-downgrade');
    Route::post('/billing/top-up', [BillingController::class, 'topUp'])->name('billing.topup');
    Route::post('/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
});

// Platform admin
Route::middleware(['auth', 'can:access-admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboard::class, 'index'])->name('dashboard');

    Route::get('/users', [AdminUsers::class, 'index'])->name('users.index');
    Route::put('/users/{user}/role', [AdminUsers::class, 'role'])->name('users.role');
    Route::put('/users/{user}/credits', [AdminUsers::class, 'credits'])->name('users.credits');

    Route::get('/worlds', [AdminCampaigns::class, 'index'])->name('worlds.index');
    Route::put('/worlds/{world}/ai-budget', [AdminCampaigns::class, 'aiBudget'])->name('worlds.ai-budget');
    Route::put('/worlds/{world}/ai-model', [AdminCampaigns::class, 'aiModel'])->name('worlds.ai-model');
    Route::put('/worlds/{world}/ddb-access', [AdminCampaigns::class, 'ddbAccess'])->name('worlds.ddb-access');

    Route::get('/compendium', [AdminCompendium::class, 'index'])->name('compendium.index');
    Route::post('/compendium/sources/{source}/import', [AdminCompendium::class, 'import'])->name('compendium.import');
    Route::get('/compendium/sources/{source}', [AdminCompendium::class, 'show'])->name('compendium.show');
    Route::get('/compendium/items/{item}/edit', [AdminCompendium::class, 'edit'])->name('compendium.items.edit');
    Route::put('/compendium/items/{item}', [AdminCompendium::class, 'update'])->name('compendium.items.update');
    Route::post('/compendium/items/{item}/draft', [AdminCompendium::class, 'draft'])->name('compendium.items.draft');

    Route::get('/billing', [AdminBilling::class, 'index'])->name('billing.index');
    Route::put('/billing', [AdminBilling::class, 'update'])->name('billing.update');

    Route::get('/ai-usage', [AdminAiUsage::class, 'index'])->name('ai-usage.index');
    Route::put('/ai-usage/pricing', [AdminAiUsage::class, 'updatePricing'])->name('ai-usage.pricing.update');
    Route::put('/ai-usage/settings', [AdminAiUsage::class, 'updateSettings'])->name('ai-usage.settings.update');

    Route::get('/attributes', [AdminAttributes::class, 'index'])->name('attributes.index');
    Route::post('/attributes', [AdminAttributes::class, 'store'])->name('attributes.store');
    Route::put('/attributes/{attribute}', [AdminAttributes::class, 'update'])->name('attributes.update');
    Route::delete('/attributes/{attribute}', [AdminAttributes::class, 'destroy'])->name('attributes.destroy');
});

require __DIR__.'/auth.php';
