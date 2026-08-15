<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GlobalAttribute;
use App\Support\Sections;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AttributesController extends Controller
{
    private const TYPES = ['text', 'longtext', 'number', 'boolean', 'date', 'select', 'url', 'reference'];

    public function index()
    {
        return Inertia::render('Admin/Attributes', [
            'attributes' => GlobalAttribute::orderBy('sort_order')->orderBy('label')->get(),
            'kindOptions' => Sections::KINDS,
            'typeOptions' => self::TYPES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        GlobalAttribute::create($data);

        return back()->with('success', "Added “{$data['label']}”.");
    }

    public function update(Request $request, GlobalAttribute $attribute)
    {
        $attribute->update($this->validated($request, $attribute));

        return back()->with('success', 'Attribute updated.');
    }

    public function destroy(GlobalAttribute $attribute)
    {
        $attribute->delete();

        return back()->with('success', 'Attribute removed.');
    }

    protected function validated(Request $request, ?GlobalAttribute $attribute = null): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'key' => ['nullable', 'string', 'max:80', Rule::unique('global_attributes', 'key')->ignore($attribute)],
            'type' => ['required', Rule::in(self::TYPES)],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:120'],
            'kinds' => ['nullable', 'array'],
            'kinds.*' => ['string', Rule::in(Sections::KINDS)],
            'ref_kinds' => ['nullable', 'array'],
            'ref_kinds.*' => ['string', Rule::in(Sections::KINDS)],
            'required' => ['boolean'],
            'visible' => ['boolean'],
            'help' => ['nullable', 'string', 'max:255'],
            'placeholder' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $data['key'] = ($data['key'] ?? '') ?: Str::slug($data['label'], '_');
        $data['required'] = $request->boolean('required');
        $data['visible'] = $request->boolean('visible');
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }
}
