<script setup>
import WorldLayout from "@/Layouts/WorldLayout.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";

const props = defineProps({
    world: Object,
    campaign: Object,
    sections: { type: Array, default: () => [] },
    stats: Object,
    recent: { type: Array, default: () => [] },
    lastSession: { type: Object, default: null },
    kickstart: {
        type: Object,
        default: () => ({ show: false, hasSeed: false, generatorsUrl: "" }),
    },
    setup: {
        type: Object,
        default: () => ({ complete: true, done: 0, total: 0, steps: [] }),
    },
    build: { type: Object, default: null },
});

// "Build my world from its seed": kick off the background job, then poll it and refresh once it lands.
const build = ref(props.build);
const starting = ref(false);
const buildError = ref("");
let pollTimer;

const buildActive = computed(
    () =>
        build.value?.status === "queued" || build.value?.status === "running",
);

const buildProgress = computed(() => {
    const planned = build.value?.planned ?? 0;
    if (!planned) return 0;
    return Math.min(100, Math.round(((build.value?.created ?? 0) / planned) * 100));
});

const schedulePoll = () => {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(pollBuild, 2000);
};

const pollBuild = async () => {
    try {
        const res = await window.axios.get(
            route("worlds.build.status", props.world.id),
        );
        build.value = res.data.build;
    } catch {
        // Keep the last known status and try again; a blip shouldn't kill the progress view.
    }
    if (buildActive.value) {
        schedulePoll();
    } else if (build.value?.status === "completed" && build.value.created > 0) {
        // Reload the dashboard so the freshly built entries and ticked checklist appear.
        router.reload({ preserveScroll: true });
    }
};

const startBuild = async () => {
    if (starting.value || buildActive.value) return;
    starting.value = true;
    buildError.value = "";
    build.value = {
        status: "queued",
        message: "Queued — warming up…",
        planned: 0,
        created: 0,
        log: [],
    };
    try {
        const res = await window.axios.post(
            route("worlds.build.store", props.world.id),
        );
        build.value = res.data.build;
        schedulePoll();
    } catch (error) {
        build.value = null;
        buildError.value =
            error.response?.data?.message ||
            "Couldn’t start the build. Please try again.";
    } finally {
        starting.value = false;
    }
};

// The checklist hides once every step is done, and the GM can dismiss it early (remembered per world).
const dismissKey = `wb-setup-hidden-${props.world.id}`;
const dismissed = ref(false);
onMounted(() => {
    dismissed.value = window.localStorage.getItem(dismissKey) === "1";
    // Resume polling if a build was already running when the dashboard loaded.
    if (buildActive.value) schedulePoll();
});
onBeforeUnmount(() => clearTimeout(pollTimer));
const dismissSetup = () => {
    dismissed.value = true;
    window.localStorage.setItem(dismissKey, "1");
};
const showSetup = computed(
    () => !props.setup.complete && props.setup.steps.length > 0 && !dismissed.value,
);

const when = (iso) =>
    iso
        ? new Date(iso).toLocaleDateString(undefined, {
              month: "short",
              day: "numeric",
          })
        : "";

const RECAP = {
    done: { label: "Recap ready", cls: "text-emerald-300" },
    analyzing: { label: "Analysing…", cls: "text-amber" },
    transcribing: { label: "Transcribing…", cls: "text-amber" },
    queued: { label: "Queued", cls: "text-muted" },
    failed: { label: "Recap failed", cls: "text-red-300" },
};

const metrics = [
    { key: "entries", label: "Entries" },
    { key: "secrets", label: "GM-only" },
    { key: "campaigns", label: "Campaigns" },
    { key: "sessions", label: "Sessions" },
    { key: "recaps", label: "Recaps" },
];
</script>

<template>
    <Head :title="campaign.name" />

    <WorldLayout :world="world">
        <!-- Build in progress: the seed is being turned into entries in the background. -->
        <div
            v-if="build && build.status !== 'completed'"
            class="mb-5 rounded-xl border border-teal/30 bg-teal/5 px-5 py-4"
        >
            <template v-if="build.status === 'failed'">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="font-display text-lg text-bright">
                            The build didn’t finish
                        </div>
                        <p class="mt-0.5 max-w-2xl text-sm text-muted">
                            {{ build.error || build.message }}
                        </p>
                    </div>
                    <button
                        class="btn-primary shrink-0"
                        :disabled="starting"
                        @click="startBuild"
                    >
                        Try again
                    </button>
                </div>
            </template>
            <template v-else>
                <div class="flex items-center gap-3">
                    <span
                        class="h-2.5 w-2.5 shrink-0 animate-pulse rounded-full bg-teal"
                    ></span>
                    <div class="font-display text-lg text-bright">
                        We’re building your world…
                    </div>
                </div>
                <p class="mt-1 text-sm text-muted">
                    {{ build.message || "Reading your notes…" }}
                    <template v-if="build.planned"
                        >({{ build.created }} of {{ build.planned }})</template
                    >
                </p>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-edge2">
                    <div
                        class="h-full rounded-full bg-teal transition-all"
                        :style="{ width: `${buildProgress}%` }"
                    ></div>
                </div>
                <p class="mt-2 text-xs text-faint">
                    You can leave this page — it keeps building in the
                    background.
                </p>
            </template>
        </div>

        <!-- Kickstart: an empty world offers to auto-build from its seed, or hand-generate content. -->
        <div
            v-if="kickstart.show && !buildActive"
            class="mb-5 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-amber/30 bg-amber/5 px-5 py-4"
        >
            <div>
                <div class="font-display text-lg text-bright">
                    Kickstart your world with AI
                </div>
                <p class="mt-0.5 max-w-2xl text-sm text-muted">
                    <template v-if="kickstart.hasSeed"
                        >You gave us a seed for this world — we can read it and
                        build out its people, places, factions and lore as
                        entries automatically.</template
                    >
                    <template v-else
                        >Generate a starting cast of people, places and factions
                        with AI, then edit and keep what fits.</template
                    >
                </p>
                <p v-if="buildError" class="mt-1 text-xs text-red-400">
                    {{ buildError }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <Link
                    :href="kickstart.generatorsUrl"
                    class="text-sm text-muted hover:text-ink"
                    >Generate manually</Link
                >
                <button
                    v-if="kickstart.hasSeed"
                    class="btn-primary"
                    :disabled="starting"
                    @click="startBuild"
                >
                    {{ starting ? "Starting…" : "Start populating data" }}
                </button>
                <Link
                    v-else
                    :href="kickstart.generatorsUrl"
                    class="btn-primary"
                    >Generate starter content</Link
                >
            </div>
        </div>

        <!-- Setup checklist: steps that take a fresh world from empty to ready. -->
        <div
            v-if="showSetup"
            class="mb-5 rounded-xl border border-edge2 bg-surface p-5"
        >
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="font-display text-lg text-bright">
                        Get your world ready
                    </div>
                    <p class="mt-0.5 text-sm text-muted">
                        {{ setup.done }} of {{ setup.total }} done — a few steps
                        to a world your players can dive into.
                    </p>
                </div>
                <button
                    class="shrink-0 text-xs text-faint hover:text-ink"
                    @click="dismissSetup"
                >
                    Hide
                </button>
            </div>

            <!-- Progress bar -->
            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-edge2">
                <div
                    class="h-full rounded-full bg-teal transition-all"
                    :style="{
                        width: `${(setup.done / setup.total) * 100}%`,
                    }"
                ></div>
            </div>

            <div class="mt-4 grid gap-2 sm:grid-cols-2">
                <Link
                    v-for="step in setup.steps"
                    :key="step.key"
                    :href="step.href"
                    class="flex items-start gap-3 rounded-lg border border-edge2 p-3 transition hover:border-teal"
                    :class="step.done ? 'opacity-70' : ''"
                >
                    <span
                        class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border text-[11px]"
                        :class="
                            step.done
                                ? 'border-teal bg-teal/20 text-teal'
                                : 'border-edge3 text-transparent'
                        "
                        >✓</span
                    >
                    <span class="min-w-0">
                        <span
                            class="block text-sm text-ink"
                            :class="step.done ? 'line-through decoration-faint' : ''"
                            >{{ step.label }}</span
                        >
                        <span class="mt-0.5 block text-xs text-faint">{{
                            step.hint
                        }}</span>
                    </span>
                </Link>
            </div>
        </div>

        <!-- Header -->
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1.5">
                <div
                    class="font-mono text-[9.5px] uppercase tracking-[0.2em] text-amber"
                >
                    World overview
                </div>
                <div
                    class="font-display text-[32px] leading-[1.05] text-bright"
                >
                    {{ campaign.name }}
                </div>
                <p
                    v-if="campaign.description"
                    class="max-w-2xl text-sm text-muted"
                >
                    {{ campaign.description }}
                </p>
            </div>
            <div class="flex flex-shrink-0 flex-wrap gap-2.5">
                <Link
                    class="btn-ghost"
                    :href="route('worlds.entries', world.id)"
                    >All entries</Link
                >
                <Link class="btn-ghost" :href="route('search.index', world.id)"
                    >Search</Link
                >
                <Link class="btn-ghost" :href="route('worlds.web', world.id)"
                    >Connections</Link
                >
                <a
                    v-if="world.visibility !== 'private'"
                    class="btn-ghost"
                    :href="world.public_url"
                    target="_blank"
                    >View on site ↗</a
                >
            </div>
        </div>

        <!-- Metrics -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <div
                v-for="m in metrics"
                :key="m.key"
                class="rounded-[9px] border border-edge2 bg-[#14161b] p-4"
            >
                <div class="font-display text-[30px] leading-none text-bright">
                    {{ stats[m.key] }}
                </div>
                <div
                    class="mt-1.5 font-mono text-[10px] uppercase tracking-[0.14em] text-faint"
                >
                    {{ m.label }}
                </div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-[1fr_320px]">
            <div class="flex flex-col gap-4">
                <!-- Last session -->
                <section class="panel p-5">
                    <div
                        class="mb-3 font-mono text-[10px] uppercase tracking-[0.16em] text-amber"
                    >
                        Last session
                    </div>
                    <div v-if="lastSession" class="flex flex-col gap-1">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <Link
                                :href="lastSession.view_url"
                                class="font-display text-xl text-bright hover:text-amber"
                                >{{ lastSession.title }}</Link
                            >
                            <span class="text-xs text-faint">{{
                                lastSession.campaign
                            }}</span>
                        </div>
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm"
                        >
                            <span v-if="lastSession.held_on" class="text-muted"
                                >Played {{ lastSession.held_on }}</span
                            >
                            <span
                                v-if="lastSession.recap_status"
                                :class="
                                    RECAP[lastSession.recap_status]?.cls ??
                                    'text-muted'
                                "
                                >{{
                                    RECAP[lastSession.recap_status]?.label ??
                                    lastSession.recap_status
                                }}</span
                            >
                        </div>
                        <div class="mt-2 flex gap-3 text-xs">
                            <Link
                                :href="lastSession.view_url"
                                class="text-amber hover:underline"
                                >Open recap</Link
                            >
                            <Link
                                :href="lastSession.edit_url"
                                class="text-faint hover:text-ink"
                                >Prep editor</Link
                            >
                        </div>
                    </div>
                    <p v-else class="text-sm text-faint">
                        No sessions logged yet.
                    </p>
                </section>

                <!-- Recently updated -->
                <section class="panel p-5">
                    <div
                        class="mb-3 font-mono text-[10px] uppercase tracking-[0.16em] text-amber"
                    >
                        Recently updated
                    </div>
                    <ul v-if="recent.length" class="flex flex-col">
                        <li
                            v-for="d in recent"
                            :key="d.id"
                            class="flex items-center justify-between gap-3 border-b border-edge2 py-2 last:border-0"
                        >
                            <Link
                                :href="route('documents.edit', d.id)"
                                class="min-w-0 flex-1 truncate text-[15px] text-ink hover:text-amber"
                                >{{ d.title }}</Link
                            >
                            <span
                                class="shrink-0 font-mono text-[10px] uppercase tracking-[0.08em] text-faint"
                                >{{ d.kindLabel }}</span
                            >
                            <span
                                class="w-12 shrink-0 text-right font-mono text-[11px] text-faint"
                                >{{ when(d.updated_at) }}</span
                            >
                        </li>
                    </ul>
                    <p v-else class="text-sm text-faint">No entries yet.</p>
                </section>
            </div>

            <!-- Right rail -->
            <div class="flex flex-col gap-4">
                <!-- Sections breakdown -->
                <section class="panel p-5">
                    <div
                        class="mb-3 font-mono text-[10px] uppercase tracking-[0.16em] text-amber"
                    >
                        By section
                    </div>
                    <ul class="flex flex-col gap-0.5">
                        <li v-for="s in sections" :key="s.slug">
                            <Link
                                :href="
                                    route('worlds.entries', {
                                        world: world.id,
                                        section: s.slug,
                                    })
                                "
                                class="flex items-center justify-between rounded-md px-2 py-1.5 text-sm text-muted hover:bg-raised hover:text-ink"
                            >
                                <span>{{ s.label }}</span>
                                <span class="font-mono text-xs text-faint">{{
                                    s.count
                                }}</span>
                            </Link>
                        </li>
                        <li v-if="!sections.length" class="text-sm text-faint">
                            No entries yet.
                        </li>
                    </ul>
                </section>

                <!-- World at a glance -->
                <section class="panel p-5 text-sm">
                    <div
                        class="mb-3 font-mono text-[10px] uppercase tracking-[0.16em] text-amber"
                    >
                        This world
                    </div>
                    <div class="flex items-center justify-between py-1">
                        <span class="text-faint">Visibility</span>
                        <span class="capitalize text-ink">{{
                            campaign.visibility
                        }}</span>
                    </div>
                    <div
                        v-if="campaign.visibility !== 'private'"
                        class="flex items-center justify-between gap-2 py-1"
                    >
                        <span class="text-faint">Public link</span>
                        <a
                            :href="world.public_url"
                            target="_blank"
                            class="truncate text-teal hover:underline"
                            >/w/{{ world.slug }}</a
                        >
                    </div>
                    <div class="mt-3 flex flex-wrap gap-3 text-xs">
                        <Link
                            :href="route('worlds.settings', world.id)"
                            class="text-amber hover:underline"
                            >World settings</Link
                        >
                        <Link
                            :href="route('media.index', world.id)"
                            class="text-faint hover:text-ink"
                            >Media</Link
                        >
                    </div>
                </section>
            </div>
        </div>
    </WorldLayout>
</template>
