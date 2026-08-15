<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import { computed, reactive, ref, watch } from "vue";

const props = defineProps({
    world: Object,
    attributes: { type: Array, default: () => [] },
    defaults: { type: Object, default: () => ({}) },
    effective: { type: Object, default: () => ({}) },
    kindOptions: { type: Array, default: () => [] },
    typeOptions: { type: Array, default: () => [] },
});

const blank = () => ({
    id: null,
    label: "",
    key: "",
    type: "text",
    multiple: false,
    kinds: [],
    options: [],
    ref_kinds: [],
    link_label: "",
    inverse_label: "",
    required: false,
    visible: true,
    help: "",
    placeholder: "",
});
const form = useForm(blank());
const editing = ref(false);

const startNew = (preset = {}) => {
    editing.value = true;
    Object.assign(form, blank(), preset);
    form.clearErrors();
};
const edit = (attr) => {
    editing.value = true;
    Object.assign(form, blank(), {
        ...attr,
        options: attr.options ?? [],
        kinds: attr.kinds ?? [],
        ref_kinds: attr.ref_kinds ?? [],
        link_label: attr.link_label ?? "",
        inverse_label: attr.inverse_label ?? "",
    });
    form.clearErrors();
};
const cancel = () => {
    editing.value = false;
    form.reset();
    form.clearErrors();
};
const save = () => {
    const opts = { preserveScroll: true, onSuccess: cancel };
    if (form.id) {
        form.put(route("worlds.attributes.update", form.id), opts);
    } else {
        form.post(route("worlds.attributes.store", props.world.id), opts);
    }
};
const remove = (attr) => {
    if (confirm(`Delete “${attr.label}”?`)) {
        router.delete(route("worlds.attributes.destroy", attr.id), {
            preserveScroll: true,
        });
    }
};

const optionsText = computed({
    get: () => (form.options || []).join("\n"),
    set: (value) => {
        form.options = value
            .split("\n")
            .map((s) => s.trim())
            .filter(Boolean);
    },
});

/* ---- filter by the kind a field is used on ---- */
const kindFilter = ref(null); // null = show every kind
const matchesFilter = (kinds) =>
    kindFilter.value === null || (kinds ?? []).includes(kindFilter.value);

// The kinds shown in the "Display order" column, honouring the filter.
const orderKinds = computed(() =>
    Object.keys(orders).filter(
        (kind) => kindFilter.value === null || kind === kindFilter.value,
    ),
);
// A world field is shown when it applies to the filtered kind (or no filter is set).
const shownAttributes = computed(() =>
    props.attributes.filter((a) => matchesFilter(a.kinds)),
);
// Platform-default groups, narrowed to the filtered kind.
const defaultKinds = computed(() =>
    Object.keys(props.defaults).filter(
        (kind) => kindFilter.value === null || kind === kindFilter.value,
    ),
);

const toggleKind = (kind) => {
    form.kinds = form.kinds.includes(kind)
        ? form.kinds.filter((k) => k !== kind)
        : [...form.kinds, kind];
};
const toggleRefKind = (kind) => {
    form.ref_kinds = form.ref_kinds.includes(kind)
        ? form.ref_kinds.filter((k) => k !== kind)
        : [...form.ref_kinds, kind];
};

/* ---- drag-to-reorder the effective field list per kind ---- */
// A local, mutable copy so a drag reorders instantly; re-synced whenever the server sends fresh props.
const orders = reactive({});
const syncOrders = () => {
    for (const kind of Object.keys(props.effective)) {
        orders[kind] = [...props.effective[kind]];
    }
};
syncOrders();
watch(() => props.effective, syncOrders, { deep: true });

const drag = reactive({ kind: null, index: null });
const onDragStart = (kind, index) => {
    drag.kind = kind;
    drag.index = index;
};
const onDragOver = (kind, index) => {
    if (drag.kind !== kind || drag.index === null || drag.index === index) {
        return;
    }
    const list = orders[kind];
    const [moved] = list.splice(drag.index, 1);
    list.splice(index, 0, moved);
    drag.index = index;
};
const onDrop = (kind) => {
    drag.kind = null;
    drag.index = null;
    router.put(
        route("worlds.attributes.reorder", props.world.id),
        { kind, order: orders[kind].map((f) => f.key) },
        { preserveScroll: true, preserveState: true },
    );
};

// Override a platform default: prefill the form with its shape (same key wins over the default).
const overrideDefault = (kind, field) =>
    startNew({
        label: field.label,
        key: field.key,
        type: field.type || "text",
        kinds: [kind],
        options: field.options ?? [],
        placeholder: field.placeholder ?? "",
    });
// Hide a platform default for this world (a same-key attribute with visible off).
const hideDefault = (kind, field) => {
    router.post(
        route("worlds.attributes.store", props.world.id),
        {
            label: field.label,
            key: field.key,
            type: field.type || "text",
            kinds: [kind],
            visible: false,
        },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Fields" />

    <WorldLayout :world="world">
        <div class="mx-auto w-full max-w-6xl space-y-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h1 class="font-display text-[28px] text-bright">Fields</h1>
                    <p class="mt-1 max-w-2xl text-sm text-muted">
                        Your world's own quick-facts fields, layered over the
                        platform defaults. Add new fields, override a default,
                        or hide one you don't use.
                    </p>
                </div>
                <button class="btn-primary shrink-0" @click="startNew()">
                    + New field
                </button>
            </div>

            <!-- Filter by the kind a field is used on -->
            <div class="flex flex-wrap items-center gap-2">
                <span class="mr-1 text-xs uppercase tracking-wider text-faint"
                    >Used on</span
                >
                <button
                    type="button"
                    class="rounded-full border px-2.5 py-0.5 text-xs"
                    :class="
                        kindFilter === null
                            ? 'border-teal bg-teal/10 text-teal'
                            : 'border-edge3 text-muted hover:text-ink'
                    "
                    @click="kindFilter = null"
                >
                    All
                </button>
                <button
                    v-for="k in kindOptions"
                    :key="k"
                    type="button"
                    class="rounded-full border px-2.5 py-0.5 text-xs capitalize"
                    :class="
                        kindFilter === k
                            ? 'border-teal bg-teal/10 text-teal'
                            : 'border-edge3 text-muted hover:text-ink'
                    "
                    @click="kindFilter = k"
                >
                    {{ k }}
                </button>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- LEFT: display order -->
                <div class="space-y-3">
                    <div class="eyebrow-muted">Display order</div>
                    <p class="text-xs text-muted">
                        Drag a field to change the order its quick-facts appear
                        on entries of that kind.
                    </p>
                    <div
                        v-for="kind in orderKinds"
                        :key="kind"
                        class="panel p-4"
                    >
                        <div
                            class="mb-2 font-mono text-[11px] uppercase tracking-wider text-amber"
                        >
                            {{ kind }}
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <div
                                v-for="(f, index) in orders[kind]"
                                :key="f.key"
                                draggable="true"
                                class="flex cursor-grab items-center gap-3 rounded-[6px] border border-edge2 bg-[#1a1d24] px-3 py-2 active:cursor-grabbing"
                                @dragstart="onDragStart(kind, index)"
                                @dragover.prevent="onDragOver(kind, index)"
                                @drop.prevent="onDrop(kind)"
                                @dragend="onDrop(kind)"
                            >
                                <span class="select-none text-faint">⠿</span>
                                <span class="text-sm text-ink">{{
                                    f.label
                                }}</span>
                                <code
                                    class="font-mono text-[11px] text-faint"
                                    >{{ f.key }}</code
                                >
                                <span
                                    v-if="f.origin === 'world'"
                                    class="ml-auto rounded-full bg-teal/10 px-1.5 py-0.5 font-mono text-[9px] uppercase tracking-wider text-teal"
                                    >custom</span
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: fields (editor + world fields + platform defaults) -->
                <div class="space-y-6">
                    <!-- Editor -->
                    <section v-if="editing" class="panel space-y-3 p-4">
                        <div class="font-display text-lg text-bright">
                            {{ form.id ? "Edit field" : "New field" }}
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1 block text-xs text-faint"
                                    >Label</span
                                >
                                <input
                                    v-model="form.label"
                                    class="field text-sm"
                                />
                                <span
                                    v-if="form.errors.label"
                                    class="mt-1 block text-xs text-red-400"
                                    >{{ form.errors.label }}</span
                                >
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs text-faint"
                                    >Key (blank = from label)</span
                                >
                                <input
                                    v-model="form.key"
                                    class="field font-mono text-sm"
                                    placeholder="population"
                                />
                                <span
                                    v-if="form.errors.key"
                                    class="mt-1 block text-xs text-red-400"
                                    >{{ form.errors.key }}</span
                                >
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs text-faint"
                                    >Type</span
                                >
                                <select
                                    v-model="form.type"
                                    class="field text-sm"
                                >
                                    <option
                                        v-for="t in typeOptions"
                                        :key="t"
                                        :value="t"
                                    >
                                        {{ t }}
                                    </option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs text-faint"
                                    >Placeholder</span
                                >
                                <input
                                    v-model="form.placeholder"
                                    class="field text-sm"
                                />
                            </label>
                        </div>

                        <div>
                            <span class="mb-1 block text-xs text-faint"
                                >Applies to</span
                            >
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="k in kindOptions"
                                    :key="k"
                                    type="button"
                                    class="rounded-full border px-2.5 py-0.5 text-xs capitalize"
                                    :class="
                                        form.kinds.includes(k)
                                            ? 'border-teal bg-teal/10 text-teal'
                                            : 'border-edge3 text-muted hover:text-ink'
                                    "
                                    @click="toggleKind(k)"
                                >
                                    {{ k }}
                                </button>
                            </div>
                            <span
                                v-if="form.errors.kinds"
                                class="mt-1 block text-xs text-red-400"
                                >{{ form.errors.kinds }}</span
                            >
                        </div>

                        <label v-if="form.type === 'select'" class="block">
                            <span class="mb-1 block text-xs text-faint"
                                >Options (one per line)</span
                            >
                            <textarea
                                v-model="optionsText"
                                rows="3"
                                class="field text-sm"
                            />
                        </label>

                        <div v-if="form.type === 'reference'" class="space-y-3">
                            <div>
                                <span class="mb-1 block text-xs text-faint"
                                    >Can reference</span
                                >
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="k in kindOptions"
                                        :key="k"
                                        type="button"
                                        class="rounded-full border px-2.5 py-0.5 text-xs capitalize"
                                        :class="
                                            form.ref_kinds.includes(k)
                                                ? 'border-teal bg-teal/10 text-teal'
                                                : 'border-edge3 text-muted hover:text-ink'
                                        "
                                        @click="toggleRefKind(k)"
                                    >
                                        {{ k }}
                                    </button>
                                </div>
                            </div>

                            <!-- Connection phrasing for the web view: how the link reads from each end. -->
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block">
                                    <span class="mb-1 block text-xs text-faint"
                                        >Reads as (from this entry)</span
                                    >
                                    <input
                                        v-model="form.link_label"
                                        class="field text-sm"
                                        placeholder="owned by"
                                    />
                                </label>
                                <label class="block">
                                    <span class="mb-1 block text-xs text-faint"
                                        >Reads as (from the target)</span
                                    >
                                    <input
                                        v-model="form.inverse_label"
                                        class="field text-sm"
                                        placeholder="owner of"
                                    />
                                </label>
                            </div>
                            <p class="text-xs text-muted">
                                Used to label the connection in the world web.
                                Leave blank to use the field name.
                            </p>
                        </div>

                        <label class="block">
                            <span class="mb-1 block text-xs text-faint"
                                >Help text (optional)</span
                            >
                            <input v-model="form.help" class="field text-sm" />
                        </label>

                        <div class="flex flex-wrap items-center gap-4">
                            <label
                                class="flex items-center gap-2 text-sm text-muted"
                            >
                                <input
                                    v-model="form.multiple"
                                    type="checkbox"
                                />
                                Allow multiple values
                            </label>
                            <label
                                class="flex items-center gap-2 text-sm text-muted"
                            >
                                <input
                                    v-model="form.required"
                                    type="checkbox"
                                />
                                Required
                            </label>
                            <label
                                class="flex items-center gap-2 text-sm text-muted"
                            >
                                <input v-model="form.visible" type="checkbox" />
                                Visible (uncheck to hide a default)
                            </label>
                        </div>

                        <div class="flex items-center gap-3">
                            <button
                                class="btn-primary"
                                :disabled="form.processing"
                                @click="save"
                            >
                                {{ form.id ? "Save" : "Add field" }}
                            </button>
                            <button
                                class="text-sm text-faint hover:text-ink"
                                @click="cancel"
                            >
                                Cancel
                            </button>
                        </div>
                    </section>

                    <!-- World fields -->
                    <section v-if="shownAttributes.length" class="space-y-3">
                        <div class="eyebrow-muted">Your fields</div>
                        <div class="panel p-0">
                            <div
                                v-for="a in shownAttributes"
                                :key="a.id"
                                class="flex items-center gap-3 border-b border-edge2 px-4 py-3 last:border-0"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm text-ink">{{
                                            a.label
                                        }}</span>
                                        <code
                                            class="font-mono text-[11px] text-faint"
                                            >{{ a.key }}</code
                                        >
                                        <span
                                            v-if="!a.visible"
                                            class="rounded-full bg-raised px-1.5 py-0.5 font-mono text-[9px] uppercase tracking-wider text-faint"
                                            >hidden</span
                                        >
                                    </div>
                                    <div
                                        class="mt-0.5 font-mono text-[10px] text-faint"
                                    >
                                        {{ a.type }} ·
                                        {{ (a.kinds || []).join(", ") || "—" }}
                                    </div>
                                </div>
                                <button
                                    class="text-xs text-muted hover:text-teal"
                                    @click="edit(a)"
                                >
                                    Edit
                                </button>
                                <button
                                    class="text-xs text-muted hover:text-red-400"
                                    @click="remove(a)"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </section>

                    <!-- Platform defaults reference -->
                    <section class="space-y-3">
                        <div class="eyebrow-muted">Platform defaults</div>
                        <div
                            v-for="kind in defaultKinds"
                            :key="kind"
                            class="panel p-4"
                        >
                            <div
                                class="mb-2 font-mono text-[11px] uppercase tracking-wider text-amber"
                            >
                                {{ kind }}
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <div
                                    v-for="f in defaults[kind]"
                                    :key="f.key"
                                    class="flex items-center gap-3 text-sm"
                                >
                                    <span class="text-ink">{{ f.label }}</span>
                                    <code
                                        class="font-mono text-[11px] text-faint"
                                        >{{ f.key }}</code
                                    >
                                    <div class="ml-auto flex gap-3">
                                        <button
                                            class="text-xs text-muted hover:text-teal"
                                            @click="overrideDefault(kind, f)"
                                        >
                                            Override
                                        </button>
                                        <button
                                            class="text-xs text-muted hover:text-red-400"
                                            @click="hideDefault(kind, f)"
                                        >
                                            Hide
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </WorldLayout>
</template>
