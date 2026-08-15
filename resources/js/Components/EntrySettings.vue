<script setup>
import SlugEditor from "@/Components/SlugEditor.vue";
import TagEditor from "@/Components/TagEditor.vue";
import { router, useForm } from "@inertiajs/vue3";
import { ref } from "vue";

const props = defineProps({
    document: Object,
    allTags: { type: Array, default: () => [] },
    entries: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    viewUrl: { type: String, default: null },
    access: {
        type: Object,
        default: () => ({ has_password: false, share_url: null }),
    },
});

const ACCENTS = [
    { value: "", hex: "" },
    { value: "teal", hex: "#6fbfc4" },
    { value: "amber", hex: "#e0a33e" },
    { value: "crimson", hex: "#d9555f" },
    { value: "violet", hex: "#9b7fe0" },
    { value: "emerald", hex: "#4fd1a1" },
    { value: "sky", hex: "#5cc8e6" },
    { value: "rose", hex: "#e06c9f" },
];

// Per-entry access control (reuses the document access/share endpoints).
const xhr = { preserveScroll: true, preserveState: true };
const password = ref("");
const setPassword = () =>
    router.put(
        route("documents.access", props.document.id),
        { password: password.value || null },
        { ...xhr, onSuccess: () => (password.value = "") },
    );
const removePassword = () =>
    router.put(
        route("documents.access", props.document.id),
        { password: null },
        xhr,
    );
const createShare = () =>
    router.post(route("documents.share", props.document.id), {}, xhr);
const revokeShare = () =>
    router.delete(route("documents.unshare", props.document.id), xhr);
const copyShare = () => {
    if (props.access.share_url) {
        navigator.clipboard?.writeText(props.access.share_url);
    }
};

// Two-way bound to the editor's own form fields (so the editor's autosave persists them).
const title = defineModel("title", { type: String, default: "" });
const summary = defineModel("summary", { type: String, default: "" });
const isPrivate = defineModel("isPrivate", { type: Boolean, default: false });
const tags = defineModel("tags", { type: Array, default: () => [] });
const isFeatured = defineModel("isFeatured", { type: Boolean, default: false });
const hideFromSearch = defineModel("hideFromSearch", {
    type: Boolean,
    default: false,
});
const coverMode = defineModel("coverMode", {
    type: String,
    default: "default",
});
const accent = defineModel("accent", { type: String, default: "" });
const publishAt = defineModel("publishAt", { type: String, default: "" });
const commentsEnabled = defineModel("commentsEnabled", {
    type: Boolean,
    default: true,
});
const showToc = defineModel("showToc", { type: Boolean, default: false });
const relatedIds = defineModel("relatedIds", {
    type: Array,
    default: () => [],
});
const templateId = defineModel("templateId", {
    type: [Number, String],
    default: null,
});

// Related-entry picker.
const relatedToAdd = ref(null);
const addRelated = () => {
    const id = Number(relatedToAdd.value);
    if (id && !relatedIds.value.includes(id)) {
        relatedIds.value = [...relatedIds.value, id];
    }
    relatedToAdd.value = null;
};
const removeRelated = (id) => {
    relatedIds.value = relatedIds.value.filter((x) => x !== id);
};
const entryTitle = (id) =>
    props.entries.find((e) => e.id === id)?.title ?? `#${id}`;

// Images upload straight away (separate from the debounced content autosave).
const cardForm = useForm({ type: "card", file: undefined });
const bannerForm = useForm({ type: "banner", file: undefined });
const upload = (imageForm, event) => {
    const file = event.target.files?.[0];
    if (!file) {
        return;
    }
    imageForm.file = file;
    imageForm.post(route("documents.image", props.document.id), {
        preserveScroll: true,
        preserveState: true,
        forceFormData: true,
        onSuccess: () => imageForm.reset("file"),
    });
    event.target.value = "";
};
const removeImage = (type) =>
    router.delete(route("documents.image.clear", props.document.id), {
        data: { type },
        preserveScroll: true,
        preserveState: true,
    });
</script>

<template>
    <div class="h-full overflow-y-auto bg-night">
        <div class="mx-auto w-full max-w-2xl space-y-6 px-6 py-8">
            <h2 class="font-display text-xl text-bright">Entry settings</h2>

            <label class="block">
                <span class="mb-1 block text-xs text-faint">Name</span>
                <input v-model="title" class="field" />
            </label>

            <div>
                <span class="mb-1 block text-xs text-faint">URL slug</span>
                <div class="flex items-center gap-2">
                    <code
                        class="rounded bg-raised px-2 py-1 font-mono text-[12px] text-muted"
                        >/{{ document.slug }}</code
                    >
                    <SlugEditor
                        :document-id="document.id"
                        :slug="document.slug"
                    />
                    <a
                        v-if="viewUrl"
                        :href="viewUrl"
                        target="_blank"
                        rel="noopener"
                        class="text-xs text-muted hover:text-amber"
                        >View on site ↗</a
                    >
                </div>
            </div>

            <label class="block">
                <span class="mb-1 block text-xs text-faint"
                    >Summary / excerpt</span
                >
                <textarea v-model="summary" rows="3" class="field" />
                <span class="mt-1 block text-[11px] text-faint"
                    >Shown on listing cards and in search results.</span
                >
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-faint">Visibility</span>
                <select v-model="isPrivate" class="field !w-auto">
                    <option :value="false">Visible to players</option>
                    <option :value="true">GM only (private)</option>
                </select>
            </label>

            <div>
                <span class="mb-1 block text-xs text-faint">Tags</span>
                <TagEditor
                    v-model="tags"
                    :suggestions="allTags"
                    list-id="entry-settings-tags"
                />
            </div>

            <!-- Display flags -->
            <div class="space-y-2 border-t border-edge3 pt-4">
                <label class="flex items-start gap-2 text-sm text-muted">
                    <input
                        v-model="isFeatured"
                        type="checkbox"
                        class="mt-0.5"
                    />
                    <span>
                        Feature on the world home
                        <span class="block text-xs text-faint"
                            >Pinned above the "start here" cards.</span
                        >
                    </span>
                </label>
                <label class="flex items-start gap-2 text-sm text-muted">
                    <input
                        v-model="hideFromSearch"
                        type="checkbox"
                        class="mt-0.5"
                    />
                    <span>
                        Hide from search engines
                        <span class="block text-xs text-faint"
                            >Adds noindex to this entry's reader page.</span
                        >
                    </span>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs text-faint"
                        >Cover banner</span
                    >
                    <select v-model="coverMode" class="field !w-auto">
                        <option value="default">Default (by section)</option>
                        <option value="show">Always show</option>
                        <option value="hide">Hide</option>
                    </select>
                </label>

                <label class="flex items-start gap-2 text-sm text-muted">
                    <input v-model="showToc" type="checkbox" class="mt-0.5" />
                    <span>
                        Show a table of contents
                        <span class="block text-xs text-faint"
                            >Built from the entry's headings.</span
                        >
                    </span>
                </label>
                <label class="flex items-start gap-2 text-sm text-muted">
                    <input
                        v-model="commentsEnabled"
                        type="checkbox"
                        class="mt-0.5"
                    />
                    <span>
                        Allow reader notes
                        <span class="block text-xs text-faint"
                            >Players can keep notes on this entry (world setting
                            must also allow it).</span
                        >
                    </span>
                </label>

                <div>
                    <span class="mb-1 block text-xs text-faint">Accent</span>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-for="a in ACCENTS"
                            :key="a.value"
                            type="button"
                            class="h-6 w-6 rounded-full border-2 text-[9px] transition"
                            :class="
                                accent === a.value
                                    ? 'border-white'
                                    : 'border-transparent hover:border-edge3'
                            "
                            :style="
                                a.hex
                                    ? { backgroundColor: a.hex }
                                    : { backgroundColor: '#2b303a' }
                            "
                            :title="a.value || 'World default'"
                            @click="accent = a.value"
                        >
                            <span v-if="!a.hex" class="text-faint">×</span>
                        </button>
                        <span class="text-[11px] text-faint"
                            >Overrides the world theme for this entry.</span
                        >
                    </div>
                </div>

                <label v-if="templates.length" class="block">
                    <span class="mb-1 block text-xs text-faint">Layout</span>
                    <select v-model="templateId" class="field !w-auto">
                        <option :value="null">Default</option>
                        <option
                            v-for="t in templates"
                            :key="t.id"
                            :value="t.id"
                        >
                            {{ t.name }}
                        </option>
                    </select>
                    <span class="mt-1 block text-[11px] text-faint"
                        >A layout template you set up in Settings →
                        Templates.</span
                    >
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs text-faint"
                        >Publish at (optional)</span
                    >
                    <input
                        v-model="publishAt"
                        type="datetime-local"
                        class="field !w-auto"
                    />
                    <span class="mt-1 block text-[11px] text-faint"
                        >Keeps the entry GM-only until this time.</span
                    >
                </label>
            </div>

            <!-- Related entries -->
            <div class="space-y-2 border-t border-edge3 pt-4">
                <span class="block text-xs font-semibold text-ink"
                    >Related entries</span
                >
                <div v-if="relatedIds.length" class="flex flex-wrap gap-1.5">
                    <span
                        v-for="id in relatedIds"
                        :key="id"
                        class="flex items-center gap-1 rounded-full bg-raised px-2.5 py-0.5 text-xs text-muted"
                    >
                        {{ entryTitle(id) }}
                        <button
                            type="button"
                            class="text-faint hover:text-red-400"
                            @click="removeRelated(id)"
                        >
                            ✕
                        </button>
                    </span>
                </div>
                <select
                    v-model="relatedToAdd"
                    class="field !w-auto text-sm"
                    @change="addRelated"
                >
                    <option :value="null">+ Add a related entry…</option>
                    <option
                        v-for="e in entries.filter(
                            (e) => !relatedIds.includes(e.id),
                        )"
                        :key="e.id"
                        :value="e.id"
                    >
                        {{ e.title }} · {{ e.kindLabel }}
                    </option>
                </select>
            </div>

            <!-- Access control -->
            <div class="space-y-3 border-t border-edge3 pt-4">
                <div class="text-xs font-semibold text-ink">Access</div>

                <div>
                    <span class="mb-1 block text-xs text-faint"
                        >Password protection</span
                    >
                    <div
                        v-if="access.has_password"
                        class="flex items-center gap-2 text-sm"
                    >
                        <span class="text-teal">🔒 Protected</span>
                        <button
                            type="button"
                            class="rounded border border-edge3 px-2 py-1 text-xs text-muted hover:border-red-500/40 hover:text-red-300"
                            @click="removePassword"
                        >
                            Remove
                        </button>
                    </div>
                    <div v-else class="flex flex-wrap items-center gap-2">
                        <input
                            v-model="password"
                            type="password"
                            class="field !w-auto"
                            placeholder="Set a password"
                        />
                        <button
                            type="button"
                            class="rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-teal hover:text-teal"
                            :disabled="!password"
                            @click="setPassword"
                        >
                            Set
                        </button>
                    </div>
                </div>

                <div>
                    <span class="mb-1 block text-xs text-faint"
                        >Share link</span
                    >
                    <div
                        v-if="access.share_url"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <code
                            class="min-w-0 flex-1 truncate rounded bg-raised px-2 py-1 font-mono text-[11px] text-muted"
                            >{{ access.share_url }}</code
                        >
                        <button
                            type="button"
                            class="shrink-0 rounded border border-edge3 px-2 py-1 text-xs text-muted hover:border-teal hover:text-teal"
                            @click="copyShare"
                        >
                            Copy
                        </button>
                        <button
                            type="button"
                            class="shrink-0 rounded border border-edge3 px-2 py-1 text-xs text-muted hover:border-red-500/40 hover:text-red-300"
                            @click="revokeShare"
                        >
                            Revoke
                        </button>
                    </div>
                    <button
                        v-else
                        type="button"
                        class="rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-teal hover:text-teal"
                        @click="createShare"
                    >
                        Create share link
                    </button>
                    <p class="mt-1 text-[11px] text-faint">
                        Anyone with the link can read this entry — even if it's
                        GM-only.
                    </p>
                </div>
            </div>

            <!-- Card image -->
            <div>
                <span class="mb-1.5 block text-xs text-faint">Card image</span>
                <div class="flex items-start gap-3">
                    <div
                        class="h-20 w-28 shrink-0 overflow-hidden rounded-md border border-edge3 bg-raised"
                    >
                        <img
                            v-if="document.card_url"
                            :src="document.card_url"
                            class="h-full w-full object-cover"
                            alt=""
                        />
                        <div
                            v-else
                            class="flex h-full items-center justify-center text-[10px] text-faint"
                        >
                            None
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <label
                            class="cursor-pointer rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-teal hover:text-teal"
                        >
                            {{ document.card_url ? "Replace" : "Upload" }}
                            <input
                                type="file"
                                accept="image/*"
                                class="hidden"
                                @change="upload(cardForm, $event)"
                            />
                        </label>
                        <button
                            v-if="document.card_url"
                            type="button"
                            class="rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-red-500/40 hover:text-red-300"
                            @click="removeImage('card')"
                        >
                            Remove
                        </button>
                    </div>
                </div>
                <span
                    v-if="cardForm.errors.file"
                    class="mt-1 block text-xs text-red-400"
                    >{{ cardForm.errors.file }}</span
                >
            </div>

            <!-- Banner image -->
            <div>
                <span class="mb-1.5 block text-xs text-faint"
                    >Banner image</span
                >
                <div
                    class="overflow-hidden rounded-md border border-edge3 bg-raised"
                    style="aspect-ratio: 16 / 5"
                >
                    <img
                        v-if="document.banner_url"
                        :src="document.banner_url"
                        class="h-full w-full object-cover"
                        alt=""
                    />
                    <div
                        v-else
                        class="flex h-full items-center justify-center text-[11px] text-faint"
                    >
                        No banner — the reader shows a subtle pattern
                    </div>
                </div>
                <div class="mt-2 flex flex-wrap gap-2">
                    <label
                        class="cursor-pointer rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-teal hover:text-teal"
                    >
                        {{ document.banner_url ? "Replace" : "Upload" }}
                        <input
                            type="file"
                            accept="image/*"
                            class="hidden"
                            @change="upload(bannerForm, $event)"
                        />
                    </label>
                    <button
                        v-if="document.banner_url"
                        type="button"
                        class="rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:border-red-500/40 hover:text-red-300"
                        @click="removeImage('banner')"
                    >
                        Remove
                    </button>
                </div>
                <span
                    v-if="bannerForm.errors.file"
                    class="mt-1 block text-xs text-red-400"
                    >{{ bannerForm.errors.file }}</span
                >
            </div>
        </div>
    </div>
</template>
