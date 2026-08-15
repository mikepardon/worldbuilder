<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps({
    world: Object,
    section: String,
    campaign: Object,
    sections: Array,
    documents: Array,
    allTags: { type: Array, default: () => [] },
    categories: Array,
});

// The section comes from the URL (the nav tabs are real links); everything else filters client-side.
const filter = computed(() => props.section || "all");
const tabs = computed(() => [
    { slug: "all", label: "Everything", count: props.documents.length },
    ...props.sections,
]);
const activeLabel = computed(
    () =>
        tabs.value.find((t) => t.slug === filter.value)?.label ?? "Everything",
);

const q = ref("");
const tagFilter = ref(null);
const visFilter = ref("all"); // all | players | gm
const showTags = ref(false); // the full tag cloud is collapsed by default to keep the header tidy

/* ---- Column sorting ---- */
const sortKey = ref("updated_at"); // title | kind | visibility | updated_at
const sortDir = ref("desc");
const setSort = (key) => {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === "asc" ? "desc" : "asc";
    } else {
        sortKey.value = key;
        sortDir.value = key === "updated_at" ? "desc" : "asc";
    }
};
const arrow = (key) =>
    sortKey.value !== key ? "" : sortDir.value === "asc" ? "↑" : "↓";
const sortVal = (d) =>
    ({
        title: d.title.toLowerCase(),
        kind: d.kindLabel.toLowerCase(),
        visibility: d.is_private ? 1 : 0,
        updated_at: d.updated_at ?? "",
    })[sortKey.value];

const filtered = computed(() => {
    let rows = props.documents;
    if (filter.value !== "all") {
        const sec = props.sections.find((s) => s.slug === filter.value);
        rows = rows.filter((d) => sec?.kinds.includes(d.kind));
    }
    if (visFilter.value === "players") rows = rows.filter((d) => !d.is_private);
    if (visFilter.value === "gm") rows = rows.filter((d) => d.is_private);
    if (tagFilter.value) {
        rows = rows.filter((d) => (d.tags ?? []).includes(tagFilter.value));
    }
    if (q.value.trim()) {
        const t = q.value.toLowerCase();
        rows = rows.filter(
            (d) =>
                d.title.toLowerCase().includes(t) ||
                (d.summary ?? "").toLowerCase().includes(t) ||
                (d.tags ?? []).some((tag) => tag.includes(t)),
        );
    }
    return [...rows].sort((a, b) => {
        const av = sortVal(a);
        const bv = sortVal(b);
        if (av < bv) return sortDir.value === "asc" ? -1 : 1;
        if (av > bv) return sortDir.value === "asc" ? 1 : -1;
        return 0;
    });
});

const badge = (d) =>
    d.is_private
        ? {
              label: "GM ONLY",
              cls: "border border-dashed border-[#6b4c14] bg-[#2b2110] text-amber",
          }
        : props.campaign.visibility === "private"
          ? {
                label: "DRAFT",
                cls: "border border-edge3 bg-[#1e222a] text-[#b8bcc4]",
            }
          : {
                label: "SHARED",
                cls: "border border-[#2f5457] bg-[#15252a] text-teal",
            };
const when = (iso) =>
    iso
        ? new Date(iso).toLocaleDateString(undefined, {
              month: "short",
              day: "numeric",
          })
        : "";
const stripes =
    "repeating-linear-gradient(48deg,#181b21 0 7px,#1b1f26 7px 14px)";

const showNew = ref(false);
const form = useForm({ title: "", kind: "location" });
const createEntry = () =>
    form.post(route("documents.store", props.campaign.id), {
        onSuccess: () => form.reset(),
    });
</script>

<template>
    <Head :title="`${activeLabel} — ${campaign.name}`" />

    <WorldLayout :world="world">
        <div class="flex items-end justify-between gap-5">
            <div class="flex flex-col gap-1.5">
                <div
                    class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber"
                >
                    {{ campaign.name }}
                </div>
                <div
                    class="font-display text-[32px] leading-[1.05] text-bright"
                >
                    {{ activeLabel }}
                </div>
            </div>
            <div class="flex flex-shrink-0 gap-2.5">
                <Link
                    class="btn-ghost"
                    :href="route('search.index', campaign.id)"
                    >Search</Link
                >
                <button class="btn-primary" @click="showNew = !showNew">
                    + New entry
                </button>
            </div>
        </div>

        <form
            v-if="showNew"
            class="panel flex flex-col gap-4 p-5"
            @submit.prevent="createEntry"
        >
            <div class="flex flex-col gap-1.5">
                <label class="text-xs font-medium text-faint">Title</label>
                <input
                    v-model="form.title"
                    class="field max-w-md"
                    placeholder="Saltmere Docks"
                />
                <p v-if="form.errors.title" class="text-sm text-red-400">
                    {{ form.errors.title }}
                </p>
            </div>
            <div class="flex flex-col gap-1.5">
                <label class="text-xs font-medium text-faint">Category</label>
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <button
                        v-for="c in categories"
                        :key="c.kind"
                        type="button"
                        class="flex flex-col gap-0.5 rounded-lg border p-3 text-left transition"
                        :class="
                            form.kind === c.kind
                                ? 'border-amber/60 bg-amber/10'
                                : 'border-edge2 hover:border-edge3'
                        "
                        @click="form.kind = c.kind"
                    >
                        <span class="flex items-center gap-2">
                            <span class="text-sm font-medium text-ink">{{
                                c.label
                            }}</span>
                            <span
                                v-if="c.hasTemplate"
                                class="rounded-full border border-edge3 px-1.5 py-0.5 font-mono text-[9px] uppercase tracking-[0.1em] text-faint"
                                >Template</span
                            >
                        </span>
                        <span class="text-xs text-muted">{{
                            c.description
                        }}</span>
                    </button>
                </div>
                <p v-if="form.errors.kind" class="text-sm text-red-400">
                    {{ form.errors.kind }}
                </p>
            </div>
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="btn-ghost"
                    @click="showNew = false"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :disabled="form.processing || !form.title"
                    class="btn-primary"
                >
                    Create entry
                </button>
            </div>
        </form>

        <div class="flex flex-wrap items-center gap-3">
            <input
                v-model="q"
                class="field max-w-[420px] flex-1"
                placeholder="Filter by name, blurb or tag…"
            />
            <div
                class="flex gap-0.5 rounded-full border border-edge3 bg-[#1a1d24] p-[3px]"
            >
                <button
                    v-for="v in [
                        ['all', 'All'],
                        ['players', 'Players'],
                        ['gm', 'GM only'],
                    ]"
                    :key="v[0]"
                    class="rounded-full px-3 py-1 font-mono text-[10.5px] tracking-[0.08em]"
                    :class="
                        visFilter === v[0]
                            ? 'bg-raised text-bright'
                            : 'text-muted hover:text-ink'
                    "
                    @click="visFilter = v[0]"
                >
                    {{ v[1] }}
                </button>
            </div>
            <div class="font-mono text-[11px] tracking-[0.08em] text-faint">
                {{ filtered.length }}
                {{ filtered.length === 1 ? "result" : "results" }}
            </div>
        </div>

        <div v-if="allTags.length" class="flex flex-wrap items-center gap-1.5">
            <button
                type="button"
                class="flex items-center gap-1.5 rounded-full border border-edge3 px-3 py-0.5 font-mono text-[10px] uppercase tracking-[0.14em] transition"
                :class="
                    showTags
                        ? 'border-teal/50 text-ink'
                        : 'text-muted hover:border-teal/50 hover:text-ink'
                "
                @click="showTags = !showTags"
            >
                {{ showTags ? "Hide tags" : "Filter by tag" }}
                <span class="text-faint">{{ allTags.length }}</span>
            </button>

            <button
                v-if="tagFilter && !showTags"
                class="flex items-center gap-1 rounded-full border border-teal bg-teal/10 px-2.5 py-0.5 text-xs text-teal"
                @click="tagFilter = null"
            >
                {{ tagFilter }} <span aria-hidden="true">✕</span>
            </button>

            <template v-if="showTags">
                <button
                    v-for="tag in allTags"
                    :key="tag"
                    class="rounded-full border px-2.5 py-0.5 text-xs transition"
                    :class="
                        tagFilter === tag
                            ? 'border-teal bg-teal/10 text-teal'
                            : 'border-edge2 text-muted hover:border-teal/50'
                    "
                    @click="tagFilter = tagFilter === tag ? null : tag"
                >
                    {{ tag }}
                </button>
            </template>
        </div>

        <!-- Entries table -->
        <div
            class="overflow-hidden rounded-[9px] border border-edge2 bg-[#14161b]"
        >
            <div
                class="grid gap-3.5 border-b border-edge2 bg-[#1a1d24] px-4 py-2.5 font-mono text-[9.5px] uppercase tracking-[0.14em] text-faint"
                style="
                    grid-template-columns: minmax(
                            230px,
                            1fr
                        ) 120px 150px 100px 70px;
                "
            >
                <button
                    class="flex items-center gap-1 text-left uppercase tracking-[0.14em] hover:text-ink"
                    @click="setSort('title')"
                >
                    Entry <span class="text-amber">{{ arrow("title") }}</span>
                </button>
                <button
                    class="flex items-center gap-1 text-left uppercase tracking-[0.14em] hover:text-ink"
                    @click="setSort('kind')"
                >
                    Type <span class="text-amber">{{ arrow("kind") }}</span>
                </button>
                <button
                    class="flex items-center gap-1 text-left uppercase tracking-[0.14em] hover:text-ink"
                    @click="setSort('visibility')"
                >
                    Visibility
                    <span class="text-amber">{{ arrow("visibility") }}</span>
                </button>
                <button
                    class="flex items-center gap-1 text-left uppercase tracking-[0.14em] hover:text-ink"
                    @click="setSort('updated_at')"
                >
                    Updated
                    <span class="text-amber">{{ arrow("updated_at") }}</span>
                </button>
                <div></div>
            </div>
            <div
                v-for="d in filtered"
                :key="d.id"
                class="grid cursor-pointer items-center gap-3.5 border-b border-edge px-4 py-3 hover:bg-[#12141a]"
                style="
                    grid-template-columns: minmax(
                            230px,
                            1fr
                        ) 120px 150px 100px 70px;
                "
                @click="router.get(route('documents.edit', d.id))"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="h-[34px] w-[34px] flex-shrink-0 rounded-[5px]"
                        :style="{ background: stripes }"
                    ></div>
                    <div class="min-w-0">
                        <div
                            class="truncate font-display text-[16px] text-bright"
                        >
                            {{ d.title }}
                        </div>
                        <div class="truncate text-[13px] font-light text-muted">
                            {{ d.summary || "—" }}
                        </div>
                        <div
                            v-if="d.tags?.length"
                            class="mt-0.5 flex flex-wrap gap-1"
                        >
                            <span
                                v-for="tag in d.tags"
                                :key="tag"
                                class="rounded-full bg-raised px-1.5 py-0.5 font-mono text-[9.5px] text-faint"
                                >{{ tag }}</span
                            >
                        </div>
                    </div>
                </div>
                <div
                    class="font-mono text-[11px] uppercase tracking-[0.08em] text-[#b8bcc4]"
                >
                    {{ d.kindLabel }}
                </div>
                <div>
                    <span
                        class="rounded-full px-2 py-1 font-mono text-[9.5px] tracking-[0.1em]"
                        :class="badge(d).cls"
                        >{{ badge(d).label }}</span
                    >
                </div>
                <div class="font-mono text-[11px] text-faint">
                    {{ when(d.updated_at) }}
                </div>
                <div class="text-right text-[13.5px] text-amber">Edit →</div>
            </div>
            <div
                v-if="!filtered.length"
                class="p-9 text-center text-[15px] font-light text-muted"
            >
                Nothing matches that. Try a different filter, or make a new
                entry.
            </div>
        </div>
    </WorldLayout>
</template>
