<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\World;
use App\Models\WorldAttribute;
use App\Support\DocFields;
use App\Support\Sections;
use App\Support\WorldNav;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** A world's own custom field definitions, layered over the platform defaults. Owner-managed. */
class WorldAttributeController extends Controller
{
    private const TYPES = ['text', 'longtext', 'number', 'boolean', 'date', 'select', 'url', 'reference'];

    public function index(World $world): Response
    {
        $this->authorize('update', $world);

        // The platform defaults per kind, for reference (so the GM knows what they can override/hide).
        $defaults = collect(Sections::KINDS)
            ->mapWithKeys(fn (string $kind): array => [$kind => DocFields::for($kind)])
            ->filter(fn (array $fields): bool => $fields !== [])
            ->all();

        // The effective, ordered fields per kind (defaults merged with this world's own) — the
        // draggable list the GM reorders. Each row is tagged with its origin so the UI can offer the
        // right actions (override/hide a default, edit/delete a world field).
        $customKeysByKind = $this->customKeysByKind($world);
        $effective = collect(Sections::KINDS)
            ->mapWithKeys(fn (string $kind): array => [$kind => collect(DocFields::for($kind, $world))
                ->map(fn (array $field): array => [
                    'key' => $field['key'],
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'origin' => in_array($field['key'], $customKeysByKind[$kind] ?? [], true) ? 'world' : 'default',
                ])
                ->values()
                ->all()])
            ->filter(fn (array $fields): bool => $fields !== [])
            ->all();

        return Inertia::render('Worlds/Attributes', [
            'world' => WorldNav::for($world),
            'attributes' => $world->customFields()->orderBy('sort_order')->orderBy('label')->get(),
            'defaults' => $defaults,
            'effective' => $effective,
            'kindOptions' => Sections::KINDS,
            'typeOptions' => self::TYPES,
        ]);
    }

    /** Persist the GM's display order of fields for one kind. */
    public function reorder(Request $request, World $world): RedirectResponse
    {
        $this->authorize('update', $world);

        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in(Sections::KINDS)],
            'order' => ['present', 'array'],
            'order.*' => ['string', 'max:80'],
        ]);

        $fieldOrder = $world->field_order ?? [];
        $fieldOrder[$data['kind']] = array_values($data['order']);
        $world->update(['field_order' => $fieldOrder]);

        return back();
    }

    /**
     * The custom (world-owned) visible field keys applying to each kind.
     *
     * @return array<string, list<string>>
     */
    private function customKeysByKind(World $world): array
    {
        $keys = [];

        foreach ($world->customFields()->where('visible', true)->get() as $attribute) {
            foreach ($attribute->kinds ?? [] as $kind) {
                $keys[$kind][] = $attribute->key;
            }
        }

        return $keys;
    }

    public function store(Request $request, World $world): RedirectResponse
    {
        $this->authorize('update', $world);

        $world->customFields()->create($this->validated($request, $world));

        return back();
    }

    public function update(Request $request, WorldAttribute $attribute): RedirectResponse
    {
        $this->authorize('update', $attribute->world);

        $attribute->update($this->validated($request, $attribute->world, $attribute));

        return back();
    }

    public function destroy(WorldAttribute $attribute): RedirectResponse
    {
        $this->authorize('update', $attribute->world);

        $attribute->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, World $world, ?WorldAttribute $attribute = null): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'key' => ['nullable', 'string', 'max:80', Rule::unique('world_attributes', 'key')->where('world_id', $world->id)->ignore($attribute)],
            'type' => ['required', Rule::in(self::TYPES)],
            'multiple' => ['boolean'],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:120'],
            'kinds' => ['nullable', 'array'],
            'kinds.*' => ['string', Rule::in(Sections::KINDS)],
            'ref_kinds' => ['nullable', 'array'],
            'ref_kinds.*' => ['string', Rule::in(Sections::KINDS)],
            'link_label' => ['nullable', 'string', 'max:60'],
            'inverse_label' => ['nullable', 'string', 'max:60'],
            'required' => ['boolean'],
            'visible' => ['boolean'],
            'help' => ['nullable', 'string', 'max:255'],
            'placeholder' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $data['key'] = ($data['key'] ?? '') ?: Str::slug($data['label'], '_');
        $data['required'] = $request->boolean('required');
        $data['visible'] = $request->boolean('visible', true);
        $data['multiple'] = $request->boolean('multiple');
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }
}
