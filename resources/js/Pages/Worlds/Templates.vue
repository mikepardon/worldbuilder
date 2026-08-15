<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { ref } from "vue";

const props = defineProps({
    world: Object,
    templates: { type: Array, default: () => [] },
    // The world's single home-page template (or null).
    homeTemplate: { type: Object, default: null },
    // [{ id, name, section }] — per-section archive templates.
    archiveTemplates: { type: Array, default: () => [] },
    // [{ id, name }] — reusable block sets you can drop into any entry template.
    reusableBlocks: { type: Array, default: () => [] },
    // [{ slug, label }] — the sections an archive template can target.
    sectionOptions: { type: Array, default: () => [] },
});

// Which section a new archive template is for — sections list very differently, so we ask first.
const archivePickerOpen = ref(false);
const archiveSection = ref("");
const startArchive = () => {
    if (!archiveSection.value) return;
    router.get(
        route("worlds.templates.create", {
            world: props.world.id,
            target: "archive",
            section: archiveSection.value,
        }),
    );
};

const remove = (t) => {
    if (confirm(`Delete “${t.name}”?`)) {
        router.delete(route("worlds.templates.destroy", t.id), {
            preserveScroll: true,
        });
    }
};
const removeBlock = (b) => {
    if (confirm(`Delete reusable block “${b.name}”?`)) {
        router.delete(route("worlds.blocks.destroy", b.id), {
            preserveScroll: true,
        });
    }
};
// Import a template from an exported JSON blob.
const importOpen = ref(false);
const importForm = useForm({ payload: "" });
const submitImport = () => {
    importForm.post(route("worlds.templates.import", props.world.id), {
        preserveScroll: true,
        onSuccess: () => {
            importOpen.value = false;
            importForm.reset();
        },
    });
};
// Import a reusable block from an exported JSON blob.
const importBlockOpen = ref(false);
const importBlockForm = useForm({ payload: "" });
const submitImportBlock = () => {
    importBlockForm.post(route("worlds.blocks.import", props.world.id), {
        preserveScroll: true,
        onSuccess: () => {
            importBlockOpen.value = false;
            importBlockForm.reset();
        },
    });
};
</script>

<template>
    <Head title="Templates" />

    <WorldLayout :world="world">
        <div class="mx-auto w-full max-w-3xl space-y-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h1 class="font-display text-[28px] text-bright">
                        Templates
                    </h1>
                    <p class="mt-1 max-w-2xl text-sm text-muted">
                        Design reader layouts block by block, then apply them to
                        entries. Some locations can look nothing like others.
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <button
                        class="text-sm text-muted hover:text-teal"
                        @click="importOpen = !importOpen"
                    >
                        Import
                    </button>
                    <Link
                        :href="route('worlds.templates.create', world.id)"
                        class="btn-primary"
                    >
                        + New template
                    </Link>
                </div>
            </div>

            <!-- Import a template from an exported JSON blob. -->
            <form
                v-if="importOpen"
                class="panel space-y-2 p-4"
                @submit.prevent="submitImport"
            >
                <div class="text-sm text-ink">Import a template</div>
                <textarea
                    v-model="importForm.payload"
                    rows="5"
                    spellcheck="false"
                    placeholder="Paste an exported template’s JSON here…"
                    class="field font-mono text-[11px]"
                ></textarea>
                <p v-if="importForm.errors.payload" class="text-xs text-red-400">
                    {{ importForm.errors.payload }}
                </p>
                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        class="btn-primary"
                        :disabled="importForm.processing || !importForm.payload"
                    >
                        Import
                    </button>
                    <button
                        type="button"
                        class="text-sm text-faint hover:text-ink"
                        @click="importOpen = false"
                    >
                        Cancel
                    </button>
                </div>
            </form>

            <!-- The reader's home page (a singleton template). -->
            <section class="panel flex items-center gap-3 p-4">
                <div class="min-w-0 flex-1">
                    <div class="text-sm text-ink">Home page</div>
                    <div class="mt-0.5 font-mono text-[10px] text-faint">
                        {{
                            homeTemplate
                                ? "Custom layout"
                                : "Using the built-in layout"
                        }}
                    </div>
                </div>
                <a
                    v-if="homeTemplate"
                    :href="route('worlds.templates.export', homeTemplate.id)"
                    class="text-xs text-muted hover:text-teal"
                    >Export</a
                >
                <Link
                    :href="route('worlds.templates.home', world.id)"
                    class="text-xs text-muted hover:text-teal"
                >
                    {{ homeTemplate ? "Edit" : "Design" }}
                </Link>
            </section>

            <!-- Per-section archive (listing) templates. -->
            <div
                class="flex items-center justify-between text-xs uppercase tracking-wide text-faint"
            >
                <span>Archive templates</span>
                <button
                    class="text-muted hover:text-teal"
                    @click="archivePickerOpen = !archivePickerOpen"
                >
                    + New archive
                </button>
            </div>

            <!-- Pick the section first — each section lists very differently. -->
            <form
                v-if="archivePickerOpen"
                class="panel flex flex-wrap items-center gap-3 p-4"
                @submit.prevent="startArchive"
            >
                <span class="text-sm text-ink">Design the listing for</span>
                <select
                    v-model="archiveSection"
                    class="rounded-md border border-edge2 bg-card px-3 py-1.5 text-sm text-ink focus:border-teal focus:outline-none"
                >
                    <option value="" disabled>Choose a section…</option>
                    <option
                        v-for="s in sectionOptions"
                        :key="s.slug"
                        :value="s.slug"
                    >
                        {{ s.label }}
                    </option>
                </select>
                <button
                    type="submit"
                    class="btn-primary"
                    :disabled="!archiveSection"
                >
                    Continue
                </button>
                <button
                    type="button"
                    class="text-sm text-faint hover:text-ink"
                    @click="archivePickerOpen = false"
                >
                    Cancel
                </button>
            </form>
            <section v-if="archiveTemplates.length" class="panel p-0">
                <div
                    v-for="t in archiveTemplates"
                    :key="t.id"
                    class="flex items-center gap-3 border-b border-edge2 px-4 py-3 last:border-0"
                >
                    <div class="min-w-0 flex-1">
                        <div class="text-sm text-ink">{{ t.name }}</div>
                        <div class="mt-0.5 font-mono text-[10px] text-faint">
                            section: {{ t.section }}
                        </div>
                    </div>
                    <a
                        :href="route('worlds.templates.export', t.id)"
                        class="text-xs text-muted hover:text-teal"
                        >Export</a
                    >
                    <Link
                        :href="route('worlds.templates.edit', t.id)"
                        class="text-xs text-muted hover:text-teal"
                        >Edit</Link
                    >
                    <button
                        class="text-xs text-muted hover:text-red-400"
                        @click="remove(t)"
                    >
                        Delete
                    </button>
                </div>
            </section>
            <p
                v-else
                class="rounded-lg border border-dashed border-edge3 p-4 text-center text-xs text-muted"
            >
                No archive templates — sections use the default listing.
            </p>

            <div class="text-xs uppercase tracking-wide text-faint">
                Entry templates
            </div>
            <section v-if="templates.length" class="panel p-0">
                <div
                    v-for="t in templates"
                    :key="t.id"
                    class="flex items-center gap-3 border-b border-edge2 px-4 py-3 last:border-0"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <div class="text-sm text-ink">{{ t.name }}</div>
                            <span
                                v-if="t.is_default"
                                class="rounded-full bg-teal/15 px-1.5 py-0.5 font-mono text-[9px] uppercase tracking-wider text-teal"
                                >Default for {{ t.kind }}</span
                            >
                        </div>
                        <div class="mt-0.5 font-mono text-[10px] text-faint">
                            {{ t.kind }} · {{ t.blocks.length }} blocks ·
                            {{ t.blocks.map((b) => b.type).join(" → ") }}
                        </div>
                    </div>
                    <a
                        :href="route('worlds.templates.export', t.id)"
                        class="text-xs text-muted hover:text-teal"
                        >Export</a
                    >
                    <Link
                        :href="route('worlds.templates.edit', t.id)"
                        class="text-xs text-muted hover:text-teal"
                    >
                        Edit
                    </Link>
                    <button
                        class="text-xs text-muted hover:text-red-400"
                        @click="remove(t)"
                    >
                        Delete
                    </button>
                </div>
            </section>
            <p
                v-else
                class="rounded-lg border border-dashed border-edge3 p-8 text-center text-sm text-muted"
            >
                No templates yet — entries use the default layout.
            </p>

            <!-- Reusable block sets: define once, drop into any entry template, edit in one place. -->
            <div
                class="flex items-center justify-between text-xs uppercase tracking-wide text-faint"
            >
                <span>Reusable blocks</span>
                <div class="flex items-center gap-3">
                    <button
                        class="text-muted hover:text-teal"
                        @click="importBlockOpen = !importBlockOpen"
                    >
                        Import
                    </button>
                    <Link
                        :href="route('worlds.blocks.create', world.id)"
                        class="text-muted hover:text-teal"
                        >+ New reusable block</Link
                    >
                </div>
            </div>
            <form
                v-if="importBlockOpen"
                class="panel space-y-2 p-4"
                @submit.prevent="submitImportBlock"
            >
                <textarea
                    v-model="importBlockForm.payload"
                    rows="4"
                    spellcheck="false"
                    placeholder="Paste an exported reusable block’s JSON here…"
                    class="field font-mono text-[11px]"
                ></textarea>
                <p
                    v-if="importBlockForm.errors.payload"
                    class="text-xs text-red-400"
                >
                    {{ importBlockForm.errors.payload }}
                </p>
                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        class="btn-primary"
                        :disabled="
                            importBlockForm.processing ||
                            !importBlockForm.payload
                        "
                    >
                        Import
                    </button>
                    <button
                        type="button"
                        class="text-sm text-faint hover:text-ink"
                        @click="importBlockOpen = false"
                    >
                        Cancel
                    </button>
                </div>
            </form>
            <section v-if="reusableBlocks.length" class="panel p-0">
                <div
                    v-for="b in reusableBlocks"
                    :key="b.id"
                    class="flex items-center gap-3 border-b border-edge2 px-4 py-3 last:border-0"
                >
                    <div class="min-w-0 flex-1 text-sm text-ink">
                        {{ b.name }}
                    </div>
                    <a
                        :href="route('worlds.blocks.export', b.id)"
                        class="text-xs text-muted hover:text-teal"
                        >Export</a
                    >
                    <Link
                        :href="route('worlds.blocks.edit', b.id)"
                        class="text-xs text-muted hover:text-teal"
                        >Edit</Link
                    >
                    <button
                        class="text-xs text-muted hover:text-red-400"
                        @click="removeBlock(b)"
                    >
                        Delete
                    </button>
                </div>
            </section>
            <p
                v-else
                class="rounded-lg border border-dashed border-edge3 p-4 text-center text-xs text-muted"
            >
                No reusable blocks yet — save one from any block’s settings, or
                create one here.
            </p>
        </div>
    </WorldLayout>
</template>
