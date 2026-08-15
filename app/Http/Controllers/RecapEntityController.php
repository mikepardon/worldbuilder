<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RecapEntity;
use App\Models\World;
use App\Services\RecapEntityMatcher;
use App\Support\RecapEntityPresenter;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Reconciling a session recap's extracted entities against the real world: searching for candidates,
 * linking to or creating a Document/Compendium entry, editing, and dismissing. Managed by the campaign GM.
 * These endpoints are called by axios and return JSON (see {@see RecapController::updateContent()} for why
 * validation is handled by hand rather than via the redirect-on-failure request validator).
 */
class RecapEntityController extends Controller
{
    public function __construct(private RecapEntityMatcher $matcher) {}

    /** Existing world entries the GM could link this entity to, filtered by an optional search term. */
    public function candidates(Request $request, RecapEntity $recapEntity): JsonResponse
    {
        $world = $this->authorizeEntity($recapEntity);

        return response()->json([
            'candidates' => $this->matcher->candidates($world, $recapEntity->type, (string) $request->query('q', '')),
        ]);
    }

    /** Edit the entity's name and description. */
    public function update(Request $request, RecapEntity $recapEntity): JsonResponse
    {
        $this->authorizeEntity($recapEntity);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $data = $validator->validated();
        $recapEntity->update([
            'name' => trim($data['name']),
            'description' => trim((string) ($data['description'] ?? '')),
        ]);

        return response()->json(RecapEntityPresenter::present($recapEntity));
    }

    /** Link the entity to an existing world entry (a Document or a Compendium item). */
    public function link(Request $request, RecapEntity $recapEntity): JsonResponse
    {
        $world = $this->authorizeEntity($recapEntity);

        $validator = Validator::make($request->all(), [
            'target' => ['required', 'in:document,compendium'],
            'id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $data = $validator->validated();

        if ($data['target'] === 'document') {
            $document = $world->documents()->whereKey($data['id'])->first();
            if ($document === null) {
                return response()->json(['message' => 'That entry is no longer available.'], 422);
            }
            $recapEntity->update([
                'linked_document_id' => $document->id,
                'linked_compendium_item_id' => null,
                'status' => 'linked',
            ]);
        } else {
            $item = $world->compendiumItems()->whereKey($data['id'])->first();
            if ($item === null) {
                return response()->json(['message' => 'That entry is no longer available.'], 422);
            }
            $recapEntity->update([
                'linked_compendium_item_id' => $item->id,
                'linked_document_id' => null,
                'status' => 'linked',
            ]);
        }

        return response()->json(RecapEntityPresenter::present($recapEntity->refresh()));
    }

    /** Create a new world entry from the entity and link it. */
    public function create(Request $request, RecapEntity $recapEntity): JsonResponse
    {
        $world = $this->authorizeEntity($recapEntity);

        $created = $this->matcher->createEntry($world, $recapEntity, (int) $request->user()->id);

        $recapEntity->update([
            'linked_document_id' => $created['target'] === 'document' ? $created['id'] : null,
            'linked_compendium_item_id' => $created['target'] === 'compendium' ? $created['id'] : null,
            'status' => 'created',
        ]);

        return response()->json(RecapEntityPresenter::present($recapEntity->refresh()));
    }

    /** Clear any link and return the entity to the review queue (also restores a dismissed one). */
    public function unlink(RecapEntity $recapEntity): JsonResponse
    {
        $this->authorizeEntity($recapEntity);

        $recapEntity->update([
            'linked_document_id' => null,
            'linked_compendium_item_id' => null,
            'status' => 'unmatched',
        ]);

        return response()->json(RecapEntityPresenter::present($recapEntity->refresh()));
    }

    /** Mark the entity as not worth tracking. */
    public function dismiss(RecapEntity $recapEntity): JsonResponse
    {
        $this->authorizeEntity($recapEntity);

        $recapEntity->update(['status' => 'dismissed']);

        return response()->json(RecapEntityPresenter::present($recapEntity));
    }

    /** Ensure the acting user manages the campaign this entity belongs to; returns the owning world. */
    private function authorizeEntity(RecapEntity $recapEntity): World
    {
        $campaign = $recapEntity->recap->session->campaign;
        $this->authorize('manage', $campaign);

        return $campaign->world;
    }

    private function validationResponse(ValidatorContract $validator): JsonResponse
    {
        return response()->json([
            'message' => 'Those changes could not be saved.',
            'errors' => $validator->errors(),
        ], 422);
    }
}
