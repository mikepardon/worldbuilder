<script setup>
import { captureError } from "@/monitoring";
import { computed, ref } from "vue";

const props = defineProps({
    entity: { type: Object, required: true },
});
const emit = defineEmits(["updated"]);

const TYPE_LABEL = {
    npc: "NPC",
    location: "location",
    faction: "faction",
    item: "item",
    monster: "monster",
    spell: "spell",
};

const mode = ref("view"); // view | edit | reconcile
const busy = ref(false);
const error = ref("");

const nameDraft = ref("");
const descDraft = ref("");

const query = ref("");
const candidates = ref([]);
const searching = ref(false);
let searchTimer = null;

const typeLabel = computed(
    () => TYPE_LABEL[props.entity.type] ?? props.entity.type,
);

const chip = computed(
    () =>
        ({
            linked: { label: "Linked", class: "bg-amber/15 text-amber" },
            created: {
                label: "Created",
                class: "bg-emerald-500/15 text-emerald-300",
            },
            dismissed: { label: "Dismissed", class: "bg-raised text-faint" },
            unmatched: { label: "Review", class: "bg-raised text-muted" },
        })[props.entity.status] ?? {
            label: "Review",
            class: "bg-raised text-muted",
        },
);

function errMsg(e) {
    return (
        e?.response?.data?.message || "Something went wrong — please try again."
    );
}

async function send(request) {
    busy.value = true;
    error.value = "";
    try {
        const res = await request();
        emit("updated", res.data);
        mode.value = "view";
    } catch (e) {
        captureError(e);
        error.value = errMsg(e);
    } finally {
        busy.value = false;
    }
}

function startEdit() {
    nameDraft.value = props.entity.name;
    descDraft.value = props.entity.description ?? "";
    error.value = "";
    mode.value = "edit";
}

function saveEdit() {
    if (!nameDraft.value.trim()) {
        error.value = "A name is required.";
        return;
    }
    send(() =>
        window.axios.put(route("recap.entities.update", props.entity.id), {
            name: nameDraft.value,
            description: descDraft.value,
        }),
    );
}

function openReconcile() {
    query.value = props.entity.name;
    candidates.value = [];
    error.value = "";
    mode.value = "reconcile";
    search();
}

function search() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(async () => {
        searching.value = true;
        try {
            const res = await window.axios.get(
                route("recap.entities.candidates", props.entity.id),
                { params: { q: query.value } },
            );
            candidates.value = res.data.candidates ?? [];
        } catch (error) {
            captureError(error);
            candidates.value = [];
        } finally {
            searching.value = false;
        }
    }, 250);
}

function link(candidate) {
    send(() =>
        window.axios.post(route("recap.entities.link", props.entity.id), {
            target: candidate.target,
            id: candidate.id,
        }),
    );
}

function createNew() {
    send(() =>
        window.axios.post(route("recap.entities.create", props.entity.id)),
    );
}

function unlink() {
    send(() =>
        window.axios.post(route("recap.entities.unlink", props.entity.id)),
    );
}

function dismiss() {
    send(() =>
        window.axios.post(route("recap.entities.dismiss", props.entity.id)),
    );
}
</script>

<template>
    <div
        class="rounded border border-edge2 bg-night/30 p-3"
        :class="entity.status === 'dismissed' ? 'opacity-60' : ''"
    >
        <!-- View -->
        <template v-if="mode === 'view'">
            <div class="mb-1 flex items-start gap-2">
                <div class="text-sm font-medium text-bright">
                    {{ entity.name }}
                </div>
                <span
                    class="ml-auto shrink-0 rounded px-1.5 py-0.5 text-[10px] uppercase tracking-wide"
                    :class="chip.class"
                    >{{ chip.label }}</span
                >
            </div>
            <p v-if="entity.description" class="text-xs text-muted">
                {{ entity.description }}
            </p>

            <a
                v-if="entity.link"
                :href="entity.link.url"
                class="mt-2 inline-block text-xs text-amber hover:underline"
                >↳ {{ entity.link.name }}</a
            >

            <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px]">
                <button class="text-faint hover:text-ink" @click="startEdit">
                    Edit
                </button>
                <template v-if="entity.status === 'unmatched'">
                    <button
                        class="text-amber hover:underline"
                        @click="openReconcile"
                    >
                        Link / create
                    </button>
                    <button class="text-faint hover:text-ink" @click="dismiss">
                        Dismiss
                    </button>
                </template>
                <template v-else-if="entity.status === 'dismissed'">
                    <button class="text-faint hover:text-ink" @click="unlink">
                        Restore
                    </button>
                </template>
                <template v-else>
                    <button class="text-faint hover:text-ink" @click="unlink">
                        Unlink
                    </button>
                </template>
            </div>
            <p v-if="error" class="mt-1 text-[11px] text-amber">{{ error }}</p>
        </template>

        <!-- Edit -->
        <template v-else-if="mode === 'edit'">
            <input
                v-model="nameDraft"
                type="text"
                class="mb-2 w-full rounded border border-edge2 bg-night/60 p-1.5 text-sm text-ink"
            />
            <textarea
                v-model="descDraft"
                rows="4"
                class="w-full rounded border border-edge2 bg-night/60 p-1.5 text-xs text-ink"
            ></textarea>
            <p v-if="error" class="mt-1 text-[11px] text-amber">{{ error }}</p>
            <div class="mt-2 flex gap-3 text-[11px]">
                <button
                    class="rounded bg-amber/90 px-2 py-0.5 font-medium text-night hover:bg-amber disabled:opacity-60"
                    :disabled="busy"
                    @click="saveEdit"
                >
                    {{ busy ? "Saving…" : "Save" }}
                </button>
                <button
                    class="text-faint hover:text-ink"
                    @click="mode = 'view'"
                >
                    Cancel
                </button>
            </div>
        </template>

        <!-- Reconcile -->
        <template v-else>
            <div class="mb-1 text-sm font-medium text-bright">
                {{ entity.name }}
            </div>
            <input
                v-model="query"
                type="text"
                placeholder="Search existing…"
                class="mb-2 w-full rounded border border-edge2 bg-night/60 p-1.5 text-xs text-ink"
                @input="search"
            />
            <p v-if="searching" class="text-[11px] text-faint">Searching…</p>
            <ul v-else-if="candidates.length" class="mb-2 space-y-1">
                <li
                    v-for="c in candidates"
                    :key="c.target + c.id"
                    class="flex items-start gap-2 rounded border border-edge2/60 p-1.5"
                >
                    <div class="min-w-0">
                        <div class="truncate text-xs text-bright">
                            {{ c.name }}
                        </div>
                        <div
                            v-if="c.summary"
                            class="truncate text-[10px] text-faint"
                        >
                            {{ c.summary }}
                        </div>
                    </div>
                    <button
                        class="ml-auto shrink-0 text-[11px] text-amber hover:underline disabled:opacity-60"
                        :disabled="busy"
                        @click="link(c)"
                    >
                        Link
                    </button>
                </li>
            </ul>
            <p v-else class="mb-2 text-[11px] text-faint">
                No matching {{ typeLabel }} found.
            </p>
            <p v-if="error" class="mb-1 text-[11px] text-amber">{{ error }}</p>
            <div class="flex gap-3 text-[11px]">
                <button
                    class="rounded bg-amber/90 px-2 py-0.5 font-medium text-night hover:bg-amber disabled:opacity-60"
                    :disabled="busy"
                    @click="createNew"
                >
                    Create new {{ typeLabel }}
                </button>
                <button
                    class="text-faint hover:text-ink"
                    @click="mode = 'view'"
                >
                    Cancel
                </button>
            </div>
        </template>
    </div>
</template>
