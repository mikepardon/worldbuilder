<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import RecapUploader from "@/Components/RecapUploader.vue";
import RecapEntityCard from "@/Components/RecapEntityCard.vue";
import RecapGuidance from "@/Components/RecapGuidance.vue";
import Markdown from "@/Components/Markdown.vue";
import { Head, Link } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";

const props = defineProps({
    session: { type: Object, required: true },
    campaign: { type: Object, required: true },
    recap: { type: Object, default: null },
});

const TYPE_LABELS = {
    location: "Locations",
    npc: "NPCs",
    faction: "Factions",
    item: "Items",
    monster: "Monsters",
    spell: "Spells",
};
const TYPE_ORDER = ["location", "npc", "faction", "monster", "item", "spell"];
const MOMENT_TYPES = ["funny", "epic", "story", "combat", "roleplay", "twist"];

const recap = ref(props.recap);
const campaignFacts = ref(props.campaign?.recap_facts ?? []);
const campaignInstructions = ref(props.campaign?.recap_instructions ?? []);
const mainTab = ref("analysis"); // analysis | transcript

function onGuidanceUpdated(data) {
    campaignFacts.value = data.recap_facts ?? [];
    campaignInstructions.value = data.recap_instructions ?? [];
}
const variant = ref("full"); // full | short | stylized
const polls = ref(0);
const retrying = ref(false);
const retryError = ref("");
// Edit mode for the analysis blocks; `draft` is a working copy committed on save.
const editing = ref(false);
const saving = ref(false);
const saveError = ref("");
const draft = ref({
    recap_full: "",
    recap_short: "",
    recap_stylized: "",
    moments: [],
    outline: [],
    next_steps: [],
    facts: [],
});
let pollTimer = null;
let pollFails = 0;

// The pipeline the GM's recording moves through, in order. `analyzing` keeps the backend's spelling.
const STAGES = [
    { key: "queued", label: "Queued" },
    { key: "transcribing", label: "Transcribing" },
    { key: "analyzing", label: "Analysing" },
];

const processing = computed(() =>
    ["queued", "transcribing", "analyzing"].includes(recap.value?.status),
);
// A text recap skips transcription, so its stepper shows only the analysis stage.
const stages = computed(() =>
    recap.value?.has_audio === false
        ? [{ key: "analyzing", label: "Analysing" }]
        : STAGES,
);
const done = computed(() => recap.value?.status === "done");
const failed = computed(() => recap.value?.status === "failed");
const showUploader = computed(
    () => !recap.value || recap.value.status === "none",
);

// Which stage is in flight; drives the stepper (earlier = done, later = pending).
const stepIndex = computed(() =>
    stages.value.findIndex((s) => s.key === recap.value?.status),
);

function stepState(index) {
    if (index < stepIndex.value) return "done";
    if (index === stepIndex.value) return "active";
    return "pending";
}

const statusLabel = computed(() => {
    switch (recap.value?.status) {
        case "transcribing":
            return "Transcribing the audio…";
        case "analyzing":
            return "Writing up the recap and analysis…";
        default:
            return "Queued — waiting to start…";
    }
});

const stalledOnQueue = computed(
    () =>
        recap.value?.status === "queued" && pollFails === 0 && polls.value >= 7,
);

const recapText = computed(() => {
    const r = recap.value ?? {};
    return (
        {
            full: r.recap_full,
            short: r.recap_short,
            stylized: r.recap_stylized,
        }[variant.value] ?? ""
    );
});

const entityGroups = computed(() => {
    const entities = recap.value?.entities ?? [];
    const groups = {};
    for (const e of entities) (groups[e.type] ??= []).push(e);
    return TYPE_ORDER.filter((t) => groups[t]?.length).map((t) => ({
        type: t,
        label: TYPE_LABELS[t] ?? t,
        items: groups[t],
    }));
});

const reviewCount = computed(
    () =>
        (recap.value?.entities ?? []).filter((e) => e.status === "unmatched")
            .length,
);

function onEntityUpdated(updated) {
    const list = recap.value?.entities ?? [];
    const index = list.findIndex((e) => e.id === updated.id);
    if (index !== -1) list.splice(index, 1, updated);
}

function onCreated(payload) {
    recap.value = payload;
    polls.value = 0;
    pollFails = 0;
    poll();
}

// Empty-state input mode: record/upload audio, or paste a transcript/notes for the same analysis.
const uploadMode = ref("audio"); // audio | text
const pastedText = ref("");
const textDetail = ref("comprehensive");
const submittingText = ref(false);
const textError = ref("");

async function submitText() {
    if (pastedText.value.trim().length < 50 || submittingText.value) return;
    submittingText.value = true;
    textError.value = "";
    try {
        const res = await window.axios.post(
            route("sessions.recap.text", props.session.id),
            { text: pastedText.value, detail_level: textDetail.value },
        );
        onCreated(res.data);
    } catch (error) {
        textError.value =
            error?.response?.data?.message ||
            "Couldn’t start — please try again.";
    } finally {
        submittingText.value = false;
    }
}

function poll() {
    if (!processing.value) return;
    pollTimer = setTimeout(async () => {
        polls.value += 1;
        try {
            const res = await window.axios.get(
                route("sessions.recap.status", props.session.id),
                { headers: { Accept: "application/json" } },
            );
            pollFails = 0;
            recap.value = res.data;
            poll();
        } catch {
            pollFails += 1;
            if (pollFails >= 4) {
                recap.value = {
                    ...recap.value,
                    status: "failed",
                    error: "Lost contact while processing.",
                };
                return;
            }
            poll();
        }
    }, 3000);
}

function setRating(n) {
    const previous = recap.value.rating;
    recap.value.rating = n;
    window.axios
        .put(route("sessions.recap.rate", props.session.id), { rating: n })
        .catch(() => (recap.value.rating = previous));
}

async function retryTranscription() {
    retrying.value = true;
    retryError.value = "";
    try {
        const res = await window.axios.post(
            route("sessions.recap.retry", props.session.id),
        );
        recap.value = res.data;
        polls.value = 0;
        pollFails = 0;
        poll();
    } catch (error) {
        retryError.value =
            error?.response?.data?.message ||
            "Couldn’t retry just now — please try again.";
    } finally {
        retrying.value = false;
    }
}

const reanalysing = ref(false);

async function reanalyse() {
    reanalysing.value = true;
    try {
        const res = await window.axios.post(
            route("sessions.recap.reanalyse", props.session.id),
        );
        recap.value = res.data;
        polls.value = 0;
        pollFails = 0;
        poll();
    } catch (error) {
        recap.value = {
            ...recap.value,
            status: "failed",
            error:
                error?.response?.data?.message ||
                "Couldn’t re-analyse just now — please try again.",
        };
    } finally {
        reanalysing.value = false;
    }
}

function startEdit() {
    const r = recap.value ?? {};
    draft.value = {
        recap_full: r.recap_full ?? "",
        recap_short: r.recap_short ?? "",
        recap_stylized: r.recap_stylized ?? "",
        moments: (r.moments ?? []).map((m) => ({ ...m })),
        outline: (r.outline ?? []).map((s) => ({ ...s })),
        next_steps: [...(r.next_steps ?? [])],
        facts: [...(r.facts ?? [])],
    };
    saveError.value = "";
    editing.value = true;
}

function cancelEdit() {
    editing.value = false;
    saveError.value = "";
}

function addMoment() {
    draft.value.moments.push({ type: "story", description: "", context: "" });
}

function removeMoment(index) {
    draft.value.moments.splice(index, 1);
}

function addScene() {
    draft.value.outline.push({ title: "", detail: "" });
}

function removeScene(index) {
    draft.value.outline.splice(index, 1);
}

function addStep() {
    draft.value.next_steps.push("");
}

function removeStep(index) {
    draft.value.next_steps.splice(index, 1);
}

function addFact() {
    draft.value.facts.push("");
}

function removeFact(index) {
    draft.value.facts.splice(index, 1);
}

async function saveEdit() {
    saving.value = true;
    saveError.value = "";
    try {
        const res = await window.axios.put(
            route("sessions.recap.content", props.session.id),
            {
                recap_full: draft.value.recap_full,
                recap_short: draft.value.recap_short,
                recap_stylized: draft.value.recap_stylized,
                moments: draft.value.moments,
                outline: draft.value.outline,
                next_steps: draft.value.next_steps,
                facts: draft.value.facts,
            },
        );
        recap.value = res.data;
        editing.value = false;
    } catch (error) {
        saveError.value =
            error?.response?.data?.message ||
            "Couldn’t save your changes — please try again.";
    } finally {
        saving.value = false;
    }
}

const sharing = ref(false);
const shareCopied = ref(false);

async function toggleShare() {
    sharing.value = true;
    try {
        const res = recap.value.share_url
            ? await window.axios.delete(
                  route("sessions.recap.unshare", props.session.id),
              )
            : await window.axios.post(
                  route("sessions.recap.share", props.session.id),
              );
        recap.value = res.data;
    } catch {
        // Leave the current sharing state as-is on failure.
    } finally {
        sharing.value = false;
    }
}

function flashCopied() {
    shareCopied.value = true;
    setTimeout(() => (shareCopied.value = false), 1500);
}

// Legacy fallback: the Clipboard API only exists in secure contexts (HTTPS/localhost), so on plain http
// fall back to a hidden textarea + execCommand, and finally a prompt the user can copy from by hand.
function legacyCopy(text) {
    try {
        const area = document.createElement("textarea");
        area.value = text;
        area.style.position = "fixed";
        area.style.opacity = "0";
        document.body.appendChild(area);
        area.focus();
        area.select();
        const ok = document.execCommand("copy");
        document.body.removeChild(area);
        return ok;
    } catch {
        return false;
    }
}

async function copyShare() {
    const url = recap.value?.share_url;
    if (!url) return;
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(url);
            flashCopied();
            return;
        }
    } catch {
        // Fall through to the legacy path below.
    }
    if (legacyCopy(url)) {
        flashCopied();
        return;
    }
    window.prompt("Copy this public link:", url);
}

const publishing = ref(false);
async function togglePublish() {
    publishing.value = true;
    try {
        const res = await window.axios.post(
            route("sessions.recap.publish", props.session.id),
            { published: !recap.value.published },
        );
        recap.value = { ...recap.value, ...res.data };
    } finally {
        publishing.value = false;
    }
}

async function replace() {
    if (pollTimer) clearTimeout(pollTimer);
    try {
        await window.axios.delete(
            route("sessions.recap.destroy", props.session.id),
        );
    } catch {
        // Even if the delete fails, drop to the uploader so the GM can retry.
    }
    recap.value = null;
}

// Resume polling when arriving on a recap that's still processing (e.g. after leaving and coming back),
// so the page advances to the finished analysis on its own.
onMounted(() => {
    if (processing.value) poll();
});

onBeforeUnmount(() => {
    if (pollTimer) clearTimeout(pollTimer);
});
</script>

<template>
    <Head :title="`${session.title} · Recap`" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl px-4 py-6">
            <!-- Header -->
            <div class="mb-5 flex flex-wrap items-center gap-3">
                <Link
                    :href="route('sessions.edit', session.id)"
                    class="text-sm text-muted hover:text-amber"
                    >← {{ session.title }}</Link
                >
                <h1 class="font-display text-2xl text-bright">Recap</h1>
                <div v-if="done" class="ml-auto flex items-center gap-3">
                    <div
                        class="flex items-center gap-0.5"
                        title="Rate this analysis"
                    >
                        <button
                            v-for="n in 5"
                            :key="n"
                            class="text-lg leading-none"
                            :class="
                                n <= (recap.rating || 0)
                                    ? 'text-amber'
                                    : 'text-edge3 hover:text-muted'
                            "
                            @click="setRating(n)"
                        >
                            ★
                        </button>
                    </div>
                    <button
                        v-if="recap.auto_publish === false"
                        class="rounded border px-2 py-1 text-xs disabled:opacity-60"
                        :class="
                            recap.published
                                ? 'border-emerald-500/40 text-emerald-300 hover:text-emerald-200'
                                : 'border-amber/50 text-amber hover:text-amber/80'
                        "
                        :disabled="publishing"
                        @click="togglePublish"
                    >
                        {{
                            recap.published
                                ? "Published ✓ — Unpublish"
                                : publishing
                                  ? "Publishing…"
                                  : "Publish to players"
                        }}
                    </button>
                    <template v-if="recap.share_url">
                        <button
                            class="rounded border border-edge3 px-2 py-1 text-xs text-amber hover:text-amber/80"
                            @click="copyShare"
                        >
                            {{ shareCopied ? "Copied!" : "Copy public link" }}
                        </button>
                        <button
                            class="rounded border border-edge3 px-2 py-1 text-xs text-muted hover:text-ink disabled:opacity-60"
                            :disabled="sharing"
                            @click="toggleShare"
                        >
                            Unshare
                        </button>
                    </template>
                    <button
                        v-else
                        class="rounded border border-edge3 px-2 py-1 text-xs text-muted hover:text-ink disabled:opacity-60"
                        :disabled="sharing"
                        @click="toggleShare"
                    >
                        {{ sharing ? "Sharing…" : "Share publicly" }}
                    </button>
                    <button
                        class="rounded border border-edge3 px-2 py-1 text-xs text-muted hover:text-ink"
                        @click="replace"
                    >
                        Replace recording
                    </button>
                </div>
            </div>

            <!-- Uploader (no recap yet): audio upload, or paste text for the same analysis -->
            <div v-if="showUploader" class="panel max-w-2xl p-5">
                <div class="mb-4 flex w-fit gap-0.5 rounded-md border border-edge2 p-0.5 text-sm">
                    <button
                        class="rounded px-3 py-1"
                        :class="uploadMode === 'audio' ? 'bg-raised text-bright' : 'text-muted hover:text-ink'"
                        @click="uploadMode = 'audio'"
                    >
                        Record / upload audio
                    </button>
                    <button
                        class="rounded px-3 py-1"
                        :class="uploadMode === 'text' ? 'bg-raised text-bright' : 'text-muted hover:text-ink'"
                        @click="uploadMode = 'text'"
                    >
                        Paste text
                    </button>
                </div>

                <RecapUploader v-if="uploadMode === 'audio'" :session="session" @created="onCreated" />

                <form v-else class="space-y-3" @submit.prevent="submitText">
                    <p class="text-sm text-muted">
                        Paste a transcript or your own notes of the session. The AI writes the recap, moments,
                        outline and entities from it — no audio needed. Costs credits by length, like an audio recap.
                    </p>
                    <textarea
                        v-model="pastedText"
                        rows="12"
                        class="w-full rounded border border-edge2 bg-night/40 p-2 font-mono text-[13px] leading-relaxed text-ink"
                        placeholder="Paste the session transcript or notes here…"
                    ></textarea>
                    <p v-if="textError" class="text-xs text-amber">{{ textError }}</p>
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2 text-xs text-muted">
                            Detail
                            <select
                                v-model="textDetail"
                                class="rounded border border-edge2 bg-night/60 px-2 py-1 text-xs text-ink"
                            >
                                <option value="comprehensive">Comprehensive</option>
                                <option value="brief">Brief</option>
                            </select>
                        </label>
                        <button
                            type="submit"
                            class="rounded bg-amber/90 px-3 py-1.5 text-sm font-medium text-night hover:bg-amber disabled:opacity-60"
                            :disabled="pastedText.trim().length < 50 || submittingText"
                        >
                            {{ submittingText ? "Starting…" : "Generate recap" }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Processing -->
            <div v-else-if="processing" class="panel max-w-2xl space-y-5 p-8">
                <!-- Stage stepper -->
                <ol class="flex items-center justify-center gap-1.5 text-xs">
                    <template v-for="(stage, i) in stages" :key="stage.key">
                        <li class="flex items-center gap-1.5">
                            <span
                                class="flex h-5 w-5 items-center justify-center rounded-full border text-[10px]"
                                :class="{
                                    'border-amber bg-amber/15 text-amber':
                                        stepState(i) === 'done',
                                    'border-amber text-amber':
                                        stepState(i) === 'active',
                                    'border-edge3 text-faint':
                                        stepState(i) === 'pending',
                                }"
                            >
                                <span v-if="stepState(i) === 'done'">✓</span>
                                <span
                                    v-else-if="stepState(i) === 'active'"
                                    class="h-2 w-2 animate-ping rounded-full bg-amber"
                                ></span>
                                <span v-else>{{ i + 1 }}</span>
                            </span>
                            <span
                                :class="
                                    stepState(i) === 'pending'
                                        ? 'text-faint'
                                        : 'text-bright'
                                "
                            >
                                {{ stage.label }}
                            </span>
                        </li>
                        <li
                            v-if="i < stages.length - 1"
                            class="h-px w-6"
                            :class="i < stepIndex ? 'bg-amber' : 'bg-edge3'"
                        ></li>
                    </template>
                </ol>

                <div class="space-y-2 text-center">
                    <p class="text-sm text-muted">{{ statusLabel }}</p>
                    <p v-if="!stalledOnQueue" class="text-xs text-faint">
                        Transcribing a long session can take a few minutes — you
                        can leave this page and come back.
                    </p>
                    <p v-else class="mx-auto max-w-sm text-xs text-amber">
                        Still queued. If you’re running this locally, a queue
                        worker needs to be running —
                        <code class="text-faint">php artisan queue:work</code>.
                    </p>
                    <p
                        v-if="recap.transcription_request_id"
                        class="text-[11px] text-faint"
                    >
                        Deepgram job
                        <code class="text-muted">{{
                            recap.transcription_request_id
                        }}</code>
                    </p>
                </div>
            </div>

            <!-- Failed -->
            <div v-else-if="failed" class="panel max-w-2xl space-y-4 p-5">
                <p class="text-sm text-amber">
                    {{ recap.error || "Something went wrong." }}
                </p>
                <p v-if="retryError" class="text-xs text-amber">
                    {{ retryError }}
                </p>
                <div class="flex gap-2">
                    <button
                        v-if="recap.has_audio"
                        class="rounded bg-amber/90 px-3 py-1.5 text-sm font-medium text-night hover:bg-amber disabled:opacity-60"
                        :disabled="retrying"
                        @click="retryTranscription"
                    >
                        {{ retrying ? "Retrying…" : "Retry transcription" }}
                    </button>
                    <button
                        v-else
                        class="rounded bg-amber/90 px-3 py-1.5 text-sm font-medium text-night hover:bg-amber disabled:opacity-60"
                        :disabled="reanalysing"
                        @click="reanalyse"
                    >
                        {{ reanalysing ? "Re-analysing…" : "Re-analyse" }}
                    </button>
                    <button
                        class="rounded border border-edge3 px-3 py-1.5 text-sm text-muted hover:text-ink"
                        @click="replace"
                    >
                        {{ recap.has_audio ? "Upload again" : "Start over" }}
                    </button>
                </div>
            </div>

            <!-- Analysis -->
            <div v-else-if="done" class="grid gap-5 lg:grid-cols-[1fr_320px]">
                <div class="space-y-5">
                    <!-- Tabs -->
                    <div class="flex gap-4 border-b border-edge text-sm">
                        <button
                            class="-mb-px border-b-2 px-1 pb-2"
                            :class="
                                mainTab === 'analysis'
                                    ? 'border-amber text-bright'
                                    : 'border-transparent text-muted hover:text-ink'
                            "
                            @click="mainTab = 'analysis'"
                        >
                            Analysis
                        </button>
                        <button
                            class="-mb-px border-b-2 px-1 pb-2"
                            :class="
                                mainTab === 'transcript'
                                    ? 'border-amber text-bright'
                                    : 'border-transparent text-muted hover:text-ink'
                            "
                            @click="mainTab = 'transcript'"
                        >
                            Transcript
                        </button>
                    </div>

                    <template v-if="mainTab === 'analysis'">
                        <!-- Edit toolbar -->
                        <div class="flex items-center justify-end gap-2">
                            <button
                                v-if="!editing"
                                class="rounded border border-edge3 px-2.5 py-1 text-xs text-muted hover:text-ink disabled:opacity-60"
                                :disabled="reanalysing"
                                title="Regenerate the analysis from the transcript, applying your facts (free)"
                                @click="reanalyse"
                            >
                                {{
                                    reanalysing ? "Re-analysing…" : "Re-analyse"
                                }}
                            </button>
                            <button
                                v-if="!editing"
                                class="rounded border border-edge3 px-2.5 py-1 text-xs text-muted hover:text-ink"
                                @click="startEdit"
                            >
                                Edit analysis
                            </button>
                            <template v-else>
                                <span
                                    v-if="saveError"
                                    class="mr-auto text-xs text-amber"
                                    >{{ saveError }}</span
                                >
                                <button
                                    class="rounded border border-edge3 px-2.5 py-1 text-xs text-muted hover:text-ink disabled:opacity-60"
                                    :disabled="saving"
                                    @click="cancelEdit"
                                >
                                    Cancel
                                </button>
                                <button
                                    class="rounded bg-amber/90 px-3 py-1 text-xs font-medium text-night hover:bg-amber disabled:opacity-60"
                                    :disabled="saving"
                                    @click="saveEdit"
                                >
                                    {{ saving ? "Saving…" : "Save changes" }}
                                </button>
                            </template>
                        </div>

                        <!-- Edit form -->
                        <template v-if="editing">
                            <section class="panel space-y-3 p-4">
                                <h2 class="font-display text-lg text-bright">
                                    Recap
                                </h2>
                                <label class="block">
                                    <span
                                        class="mb-1 block text-xs uppercase tracking-wide text-faint"
                                        >Full</span
                                    >
                                    <textarea
                                        v-model="draft.recap_full"
                                        rows="10"
                                        class="w-full rounded border border-edge2 bg-night/40 p-2 text-sm leading-relaxed text-ink"
                                    ></textarea>
                                </label>
                                <label class="block">
                                    <span
                                        class="mb-1 block text-xs uppercase tracking-wide text-faint"
                                        >Short</span
                                    >
                                    <textarea
                                        v-model="draft.recap_short"
                                        rows="3"
                                        class="w-full rounded border border-edge2 bg-night/40 p-2 text-sm leading-relaxed text-ink"
                                    ></textarea>
                                </label>
                                <label class="block">
                                    <span
                                        class="mb-1 block text-xs uppercase tracking-wide text-faint"
                                        >Stylized</span
                                    >
                                    <textarea
                                        v-model="draft.recap_stylized"
                                        rows="4"
                                        class="w-full rounded border border-edge2 bg-night/40 p-2 text-sm leading-relaxed text-ink"
                                    ></textarea>
                                </label>
                            </section>

                            <section class="panel space-y-3 p-4">
                                <div class="flex items-center justify-between">
                                    <h2
                                        class="font-display text-lg text-bright"
                                    >
                                        Memorable Moments
                                    </h2>
                                    <button
                                        class="text-xs text-amber hover:underline"
                                        @click="addMoment"
                                    >
                                        + Add moment
                                    </button>
                                </div>
                                <div
                                    v-for="(m, i) in draft.moments"
                                    :key="i"
                                    class="space-y-2 rounded border border-edge2 bg-night/40 p-3"
                                >
                                    <div class="flex items-center gap-2">
                                        <select
                                            v-model="m.type"
                                            class="rounded border border-edge2 bg-night/60 px-2 py-1 text-xs capitalize text-ink"
                                        >
                                            <option
                                                v-for="t in MOMENT_TYPES"
                                                :key="t"
                                                :value="t"
                                            >
                                                {{ t }}
                                            </option>
                                        </select>
                                        <button
                                            class="ml-auto text-xs text-faint hover:text-amber"
                                            @click="removeMoment(i)"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                    <textarea
                                        v-model="m.description"
                                        rows="2"
                                        placeholder="What happened"
                                        class="w-full rounded border border-edge2 bg-night/60 p-2 text-sm text-ink"
                                    ></textarea>
                                    <input
                                        v-model="m.context"
                                        type="text"
                                        placeholder="Context (optional)"
                                        class="w-full rounded border border-edge2 bg-night/60 p-2 text-xs text-ink"
                                    />
                                </div>
                                <p
                                    v-if="!draft.moments.length"
                                    class="text-xs text-faint"
                                >
                                    No moments yet — add one above.
                                </p>
                            </section>

                            <section class="panel space-y-3 p-4">
                                <div class="flex items-center justify-between">
                                    <h2
                                        class="font-display text-lg text-bright"
                                    >
                                        Outline
                                    </h2>
                                    <button
                                        class="text-xs text-amber hover:underline"
                                        @click="addScene"
                                    >
                                        + Add scene
                                    </button>
                                </div>
                                <div
                                    v-for="(s, i) in draft.outline"
                                    :key="i"
                                    class="space-y-2 rounded border border-edge2 bg-night/40 p-3"
                                >
                                    <div class="flex items-center gap-2">
                                        <input
                                            v-model="s.title"
                                            type="text"
                                            placeholder="Scene title"
                                            class="w-full rounded border border-edge2 bg-night/60 p-2 text-sm text-ink"
                                        />
                                        <button
                                            class="shrink-0 text-xs text-faint hover:text-amber"
                                            @click="removeScene(i)"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                    <textarea
                                        v-model="s.detail"
                                        rows="2"
                                        placeholder="Detail (optional)"
                                        class="w-full rounded border border-edge2 bg-night/60 p-2 text-sm text-ink"
                                    ></textarea>
                                </div>
                                <p
                                    v-if="!draft.outline.length"
                                    class="text-xs text-faint"
                                >
                                    No scenes yet — add one above.
                                </p>
                            </section>

                            <section class="panel space-y-3 p-4">
                                <div class="flex items-center justify-between">
                                    <h2
                                        class="font-display text-lg text-bright"
                                    >
                                        Next Steps
                                    </h2>
                                    <button
                                        class="text-xs text-amber hover:underline"
                                        @click="addStep"
                                    >
                                        + Add step
                                    </button>
                                </div>
                                <div
                                    v-for="(step, i) in draft.next_steps"
                                    :key="i"
                                    class="flex items-center gap-2"
                                >
                                    <span class="text-faint">•</span>
                                    <input
                                        v-model="draft.next_steps[i]"
                                        type="text"
                                        placeholder="What the party plans to do next"
                                        class="w-full rounded border border-edge2 bg-night/60 p-2 text-sm text-ink"
                                    />
                                    <button
                                        class="shrink-0 text-xs text-faint hover:text-amber"
                                        @click="removeStep(i)"
                                    >
                                        Remove
                                    </button>
                                </div>
                                <p
                                    v-if="!draft.next_steps.length"
                                    class="text-xs text-faint"
                                >
                                    No next steps yet — add one above.
                                </p>
                            </section>

                            <section class="panel space-y-3 p-4">
                                <div class="flex items-center justify-between">
                                    <h2
                                        class="font-display text-lg text-bright"
                                    >
                                        Facts &amp; Corrections
                                    </h2>
                                    <button
                                        class="text-xs text-amber hover:underline"
                                        @click="addFact"
                                    >
                                        + Add fact
                                    </button>
                                </div>
                                <p class="text-xs text-faint">
                                    Notes for the AI — e.g. “the players say
                                    ‘Brian’ but the character is Clayton”. Save,
                                    then Re-analyse to apply them (free — no
                                    re-transcription).
                                </p>
                                <div
                                    v-for="(fact, i) in draft.facts"
                                    :key="i"
                                    class="flex items-center gap-2"
                                >
                                    <input
                                        v-model="draft.facts[i]"
                                        type="text"
                                        placeholder="A fact or correction for this session"
                                        class="w-full rounded border border-edge2 bg-night/60 p-2 text-sm text-ink"
                                    />
                                    <button
                                        class="shrink-0 text-xs text-faint hover:text-amber"
                                        @click="removeFact(i)"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </section>
                        </template>

                        <!-- Read-only view -->
                        <template v-else>
                            <!-- Recap -->
                            <section class="panel p-4">
                                <div
                                    class="mb-2 flex items-center justify-between"
                                >
                                    <h2
                                        class="font-display text-lg text-bright"
                                    >
                                        Recap
                                    </h2>
                                    <div class="flex gap-1 text-xs">
                                        <button
                                            v-for="v in [
                                                'full',
                                                'short',
                                                'stylized',
                                            ]"
                                            :key="v"
                                            class="rounded px-2 py-0.5 capitalize"
                                            :class="
                                                variant === v
                                                    ? 'bg-raised text-bright'
                                                    : 'text-muted hover:text-ink'
                                            "
                                            @click="variant = v"
                                        >
                                            {{ v }}
                                        </button>
                                    </div>
                                </div>
                                <div class="text-muted">
                                    <Markdown
                                        v-if="recapText"
                                        :source="recapText"
                                    />
                                    <span v-else>—</span>
                                </div>
                            </section>

                            <!-- Memorable moments -->
                            <section
                                v-if="recap.moments?.length"
                                class="panel p-4"
                            >
                                <h2
                                    class="mb-3 font-display text-lg text-bright"
                                >
                                    Memorable Moments
                                    <span class="text-sm text-faint"
                                        >({{ recap.moments.length }})</span
                                    >
                                </h2>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div
                                        v-for="(m, i) in recap.moments"
                                        :key="i"
                                        class="rounded border border-edge2 bg-night/40 p-3"
                                    >
                                        <span
                                            class="mb-1 inline-block rounded bg-raised px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-amber"
                                            >{{ m.type }}</span
                                        >
                                        <p class="text-sm text-ink">
                                            <Markdown
                                                :source="m.description"
                                                inline
                                            />
                                        </p>
                                        <p
                                            v-if="m.context"
                                            class="mt-1 text-xs text-faint"
                                        >
                                            <Markdown
                                                :source="m.context"
                                                inline
                                            />
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <!-- Outline -->
                            <section
                                v-if="recap.outline?.length"
                                class="panel p-4"
                            >
                                <h2
                                    class="mb-3 font-display text-lg text-bright"
                                >
                                    Outline
                                </h2>
                                <ol class="space-y-2">
                                    <li
                                        v-for="(s, i) in recap.outline"
                                        :key="i"
                                        class="border-l-2 border-edge2 pl-3"
                                    >
                                        <div class="text-sm text-bright">
                                            {{ s.title }}
                                        </div>
                                        <div
                                            v-if="s.detail"
                                            class="text-sm text-muted"
                                        >
                                            <Markdown
                                                :source="s.detail"
                                                inline
                                            />
                                        </div>
                                    </li>
                                </ol>
                            </section>

                            <!-- Next Steps -->
                            <section
                                v-if="recap.next_steps?.length"
                                class="panel p-4"
                            >
                                <h2
                                    class="mb-3 font-display text-lg text-bright"
                                >
                                    Next Steps
                                </h2>
                                <ul class="space-y-1.5">
                                    <li
                                        v-for="(step, i) in recap.next_steps"
                                        :key="i"
                                        class="flex gap-2 text-sm text-muted"
                                    >
                                        <span class="text-amber">•</span>
                                        <span
                                            ><Markdown :source="step" inline
                                        /></span>
                                    </li>
                                </ul>
                            </section>

                            <!-- Facts & corrections -->
                            <section
                                v-if="recap.facts?.length"
                                class="panel p-4"
                            >
                                <h2
                                    class="mb-3 font-display text-lg text-bright"
                                >
                                    Facts &amp; Corrections
                                </h2>
                                <ul class="space-y-1.5">
                                    <li
                                        v-for="(fact, i) in recap.facts"
                                        :key="i"
                                        class="flex gap-2 text-sm text-muted"
                                    >
                                        <span class="text-faint">•</span>
                                        <span>{{ fact }}</span>
                                    </li>
                                </ul>
                            </section>
                        </template>

                        <RecapGuidance
                            :campaign-id="campaign.id"
                            :facts="campaignFacts"
                            :instructions="campaignInstructions"
                            @updated="onGuidanceUpdated"
                        />
                    </template>

                    <!-- Transcript -->
                    <section v-else class="panel p-4">
                        <h2 class="mb-2 font-display text-lg text-bright">
                            Transcript
                        </h2>
                        <pre
                            class="max-h-[70vh] overflow-y-auto whitespace-pre-wrap font-sans text-sm leading-relaxed text-muted"
                            >{{ recap.transcript || "—" }}</pre>
                    </section>
                </div>

                <!-- Entity panels -->
                <aside class="space-y-4">
                    <p v-if="reviewCount" class="text-xs text-muted">
                        {{ reviewCount }} to review — link each to your world or
                        create it.
                    </p>
                    <section
                        v-for="group in entityGroups"
                        :key="group.type"
                        class="panel space-y-2 p-4"
                    >
                        <h2 class="mb-1 font-display text-base text-bright">
                            {{ group.label }}
                            <span class="text-xs text-faint"
                                >({{ group.items.length }})</span
                            >
                        </h2>
                        <RecapEntityCard
                            v-for="e in group.items"
                            :key="e.id"
                            :entity="e"
                            @updated="onEntityUpdated"
                        />
                    </section>
                    <p
                        v-if="!entityGroups.length"
                        class="panel p-4 text-sm text-faint"
                    >
                        No entities were pulled from this session.
                    </p>
                </aside>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
