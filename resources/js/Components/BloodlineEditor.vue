<script setup>
import BloodlineChart from "@/Components/BloodlineChart.vue";
import EntryPicker from "@/Components/EntryPicker.vue";
import { Link, router } from "@inertiajs/vue3";
import { computed, reactive, ref, watch } from "vue";

const props = defineProps({
    campaign: Object,
    document: Object,
    entries: { type: Array, default: () => [] },
    viewUrl: { type: String, default: null },
});

const PARENT_TYPES = ["biological", "adopted", "step", "foster"];
const uid = () =>
    typeof crypto !== "undefined" && crypto.randomUUID
        ? crypto.randomUUID()
        : `m${Math.floor(Math.random() * 1e9)}`;

const form = reactive({
    title: props.document.title,
    summary: props.document.summary ?? "",
    is_private: props.document.is_private,
    members: [...(props.document.data?.members ?? [])].map((member) => ({
        id: member.id ?? uid(),
        name: member.name ?? "",
        subtitle: member.subtitle ?? "",
        link: member.link ?? null,
        // Normalise legacy bare-id parents to { id, type }.
        parents: (member.parents ?? []).map((p) =>
            typeof p === "object" ? p : { id: p, type: "biological" },
        ),
        partners: [...(member.partners ?? [])],
    })),
});

// A linked member wears the linked entry's portrait; the chart just reads `image`.
const entriesById = computed(() =>
    Object.fromEntries(props.entries.map((e) => [e.id, e])),
);
const displayMembers = computed(() =>
    form.members.map((m) => ({
        ...m,
        image:
            (m.link != undefined ? entriesById.value[m.link]?.image : null) ??
            m.image ??
            null,
    })),
);

const saved = ref(true);
let timer = null;
const save = () => {
    router.put(
        route("documents.update", props.document.id),
        {
            title: form.title,
            summary: form.summary,
            is_private: form.is_private,
            data: { ...(props.document.data || {}), members: form.members },
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => (saved.value = true),
        },
    );
};
watch(
    form,
    () => {
        saved.value = false;
        clearTimeout(timer);
        timer = setTimeout(save, 1000);
    },
    { deep: true },
);

/* ---- add / edit member via a modal that already knows the connection ---- */
const memberById = (id) => form.members.find((m) => m.id === id);
const modal = reactive({
    open: false,
    mode: "add", // add | edit
    editingId: null,
    connect: null, // { kind: 'child', parentId } | { kind: 'partner', partnerId } | null
    name: "",
    subtitle: "",
    link: null,
    image: null,
    parentType: "biological",
});

const openAdd = (connect) => {
    Object.assign(modal, {
        open: true,
        mode: "add",
        editingId: null,
        connect,
        name: "",
        subtitle: "",
        link: null,
        image: null,
        parentType: "biological",
    });
};
const openEdit = (id) => {
    const member = memberById(id);
    if (!member) return;
    Object.assign(modal, {
        open: true,
        mode: "edit",
        editingId: id,
        connect: null,
        name: member.name,
        subtitle: member.subtitle,
        link: member.link,
        image: member.image ?? null,
        parentType: "biological",
    });
};
const closeModal = () => (modal.open = false);

// Upload a portrait for a member who isn't linked to an entry (reuses the world media store).
const uploading = ref(false);
const uploadImage = async (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    uploading.value = true;
    const data = new FormData();
    data.append("file", file);
    try {
        const res = await window.axios.post(
            route("media.store", props.campaign.id),
            data,
        );
        modal.image = res.data.url;
    } finally {
        uploading.value = false;
        event.target.value = "";
    }
};

const submitModal = () => {
    if (modal.mode === "edit") {
        const member = memberById(modal.editingId);
        if (member) {
            member.name = modal.name;
            member.subtitle = modal.subtitle;
            member.link = modal.link;
            member.image = modal.image;
        }
        closeModal();
        return;
    }

    const member = {
        id: uid(),
        name: modal.name,
        subtitle: modal.subtitle,
        link: modal.link,
        image: modal.image,
        parents: [],
        partners: [],
    };
    if (modal.connect?.kind === "child") {
        member.parents.push({
            id: modal.connect.parentId,
            type: modal.parentType,
        });
    } else if (modal.connect?.kind === "partner") {
        member.partners.push(modal.connect.partnerId);
        const partner = memberById(modal.connect.partnerId);
        if (partner && !partner.partners.includes(member.id)) {
            partner.partners.push(member.id);
        }
    }
    form.members.push(member);
    closeModal();
};

const removeMember = (id) => {
    const index = form.members.findIndex((m) => m.id === id);
    if (index === -1) return;
    form.members.splice(index, 1);
    for (const member of form.members) {
        member.parents = member.parents.filter((p) => p.id !== id);
        member.partners = member.partners.filter((p) => p !== id);
    }
};

const modalTitle = () => {
    if (modal.mode === "edit") return "Edit member";
    if (modal.connect?.kind === "child") return "Add a child";
    if (modal.connect?.kind === "partner") return "Add a partner";
    return "Add a member";
};

/* ---- Muse: a conversational assistant that can add people to the tree ---- */
const muse = reactive({
    open: true,
    input: "",
    loading: false,
    error: "",
    messages: [],
});

// Append the AI's proposed people: new ids get fresh ones; references to people already in the tree
// (existing ids) are kept as-is, so Muse can wire new members onto the current tree.
const applyGenerated = (members) => {
    const existingIds = new Set(form.members.map((m) => m.id));
    const idMap = {};
    for (const m of members) {
        if (!existingIds.has(m.id)) idMap[m.id] = uid();
    }
    const resolve = (id) => (existingIds.has(id) ? id : idMap[id]);
    for (const m of members) {
        if (existingIds.has(m.id)) continue; // only add new people
        form.members.push({
            id: idMap[m.id],
            name: m.name,
            subtitle: m.subtitle ?? "",
            link: null,
            image: null,
            parents: (m.parents ?? [])
                .map((p) => ({ id: resolve(p), type: "biological" }))
                .filter((p) => p.id),
            partners: (m.partners ?? []).map((p) => resolve(p)).filter(Boolean),
        });
    }
};

const send = async () => {
    const text = muse.input.trim();
    if (!text || muse.loading) return;
    muse.input = "";
    muse.error = "";
    const history = muse.messages.map((m) => ({
        role: m.role,
        content: m.content,
    }));
    muse.messages.push({ role: "user", content: text });
    muse.loading = true;
    try {
        const res = await window.axios.post(
            route("documents.ai.bloodline", props.document.id),
            {
                prompt: text,
                history,
                existing: form.members.map((m) => ({ id: m.id, name: m.name })),
            },
        );
        const added = res.data.members ?? [];
        applyGenerated(added);
        const reply =
            res.data.reply ||
            (added.length
                ? `Added ${added.length} ${added.length === 1 ? "person" : "people"}.`
                : "");
        muse.messages.push({
            role: "assistant",
            content: reply,
            added: added.length,
        });
    } catch (e) {
        muse.error = e.response?.data?.message || "Muse request failed.";
    } finally {
        muse.loading = false;
    }
};
</script>

<template>
    <div class="flex h-full flex-col">
        <!-- Top bar -->
        <div
            class="flex flex-wrap items-center gap-3 border-b border-edge bg-surface px-6 py-2.5"
        >
            <Link
                :href="route('worlds.show', campaign.id)"
                class="rounded-md border border-edge3 px-2.5 py-1 text-sm text-[#c8ccd3] transition hover:border-teal hover:text-teal"
                >← Back</Link
            >
            <input
                v-model="form.title"
                class="field !w-auto min-w-[200px] font-display text-lg"
                placeholder="House Alduin"
            />
            <input
                v-model="form.summary"
                class="field !w-auto min-w-[240px] flex-1 text-sm"
                placeholder="The dynasty that ruled the coast…"
            />
            <label class="flex items-center gap-2 text-sm text-muted">
                <input v-model="form.is_private" type="checkbox" />
                Private
            </label>
            <div class="ml-auto flex items-center gap-3">
                <button
                    class="rounded-md border border-teal/50 px-2.5 py-1 text-sm text-teal hover:bg-teal/10"
                    @click="openAdd(null)"
                >
                    + Add person
                </button>
                <span class="font-mono text-[11px] text-faint">{{
                    saved ? "✓ Saved" : "Saving…"
                }}</span>
                <a
                    v-if="viewUrl"
                    :href="viewUrl"
                    target="_blank"
                    class="rounded-md border border-edge3 px-2.5 py-1 text-sm text-muted transition hover:border-teal hover:text-teal"
                    >View ↗</a
                >
            </div>
        </div>

        <!-- Tree (the editor: hover a card for its + child / + partner buttons) + Muse column. -->
        <div class="flex min-h-0 flex-1">
            <div class="min-h-0 flex-1 p-3">
                <BloodlineChart
                    :members="displayMembers"
                    fill
                    editable
                    @add-child="
                        (id) => openAdd({ kind: 'child', parentId: id })
                    "
                    @add-partner="
                        (id) => openAdd({ kind: 'partner', partnerId: id })
                    "
                    @add-root="openAdd(null)"
                    @edit="openEdit"
                    @delete="removeMember"
                />
            </div>

            <!-- Muse: a conversational assistant, collapsible like the campaign editor. -->
            <div class="flex min-h-0 flex-col border-l border-edge2 bg-surface">
                <button
                    v-if="!muse.open"
                    class="flex h-full w-full flex-col items-center gap-2 py-3 text-faint transition hover:text-amber"
                    title="Open Muse"
                    @click="muse.open = true"
                >
                    <span class="text-sm">✦</span>
                    <span
                        class="text-[10px] uppercase tracking-[0.16em]"
                        style="writing-mode: vertical-rl"
                        >Muse</span
                    >
                </button>
                <template v-else>
                    <div
                        class="flex w-80 items-center justify-between border-b border-edge2 px-4 py-2.5"
                    >
                        <span
                            class="font-mono text-[11px] uppercase tracking-[0.18em] text-amber"
                            >✦ Muse</span
                        >
                        <div class="flex items-center gap-3">
                            <button
                                v-if="muse.messages.length"
                                class="text-xs text-faint hover:text-ink"
                                @click="muse.messages = []"
                            >
                                clear
                            </button>
                            <button
                                class="text-faint hover:text-ink"
                                title="Collapse Muse"
                                @click="muse.open = false"
                            >
                                »
                            </button>
                        </div>
                    </div>
                    <div class="w-80 flex-1 space-y-3 overflow-y-auto p-4">
                        <p
                            v-if="!muse.messages.length"
                            class="text-sm text-faint"
                        >
                            Ask Muse to draft a family, add children to someone,
                            or invent a rival house — it can see this bloodline
                            and adds people straight to the tree.
                        </p>
                        <div v-for="(m, i) in muse.messages" :key="i">
                            <div
                                v-if="m.role === 'user'"
                                class="ml-6 rounded-lg bg-amber/90 px-3 py-2 text-sm text-night"
                            >
                                {{ m.content }}
                            </div>
                            <div v-else class="mr-2 text-sm text-ink">
                                <p>{{ m.content }}</p>
                                <p
                                    v-if="m.added"
                                    class="mt-1 text-xs text-teal"
                                >
                                    ✓ Added {{ m.added }}
                                    {{ m.added === 1 ? "person" : "people" }} to
                                    the tree
                                </p>
                            </div>
                        </div>
                        <p v-if="muse.loading" class="text-sm text-faint">
                            Thinking…
                        </p>
                        <p v-if="muse.error" class="text-sm text-red-400">
                            {{ muse.error }}
                        </p>
                    </div>
                    <form
                        class="w-80 border-t border-edge2 p-3"
                        @submit.prevent="send"
                    >
                        <textarea
                            v-model="muse.input"
                            rows="2"
                            class="field text-sm"
                            placeholder="Draft a three-generation merchant dynasty…"
                            @keydown.enter.exact.prevent="send"
                        />
                        <button
                            type="submit"
                            class="btn-primary mt-2 w-full justify-center"
                            :disabled="muse.loading || !muse.input.trim()"
                        >
                            Send
                        </button>
                    </form>
                </template>
            </div>
        </div>

        <!-- Add / edit modal -->
        <div
            v-if="modal.open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            @click.self="closeModal"
        >
            <div class="panel w-full max-w-md space-y-4 p-5">
                <div class="font-display text-lg text-bright">
                    {{ modalTitle() }}
                </div>
                <label class="block">
                    <span class="mb-1 block text-xs text-faint">Name</span>
                    <input
                        v-model="modal.name"
                        class="field text-sm"
                        placeholder="Alya Alduin"
                        autofocus
                        @keydown.enter.prevent="submitModal"
                    />
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs text-faint"
                        >Title / dates</span
                    >
                    <input
                        v-model="modal.subtitle"
                        class="field text-sm"
                        placeholder="The Seducer · 1050–1105"
                    />
                </label>
                <div>
                    <span class="mb-1 block text-xs text-faint"
                        >Linked entry (optional)</span
                    >
                    <EntryPicker
                        :model-value="modal.link"
                        :entries="entries"
                        placeholder="Link to a person…"
                        @update:model-value="modal.link = $event"
                    />
                </div>
                <!-- A linked member wears the entry's portrait; an unlinked one can upload its own. -->
                <div v-if="modal.link == undefined">
                    <span class="mb-1 block text-xs text-faint"
                        >Portrait (optional)</span
                    >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex h-12 w-12 items-center justify-center overflow-hidden rounded-full border border-edge2 bg-raised"
                        >
                            <img
                                v-if="modal.image"
                                :src="modal.image"
                                class="h-full w-full object-cover"
                                alt=""
                            />
                            <span v-else class="text-xs text-faint">—</span>
                        </span>
                        <label
                            class="cursor-pointer rounded-md border border-edge3 px-2.5 py-1 text-sm text-muted transition hover:border-teal hover:text-teal"
                        >
                            {{ uploading ? "Uploading…" : "Upload image" }}
                            <input
                                type="file"
                                accept="image/*"
                                class="hidden"
                                @change="uploadImage"
                            />
                        </label>
                        <button
                            v-if="modal.image"
                            class="text-xs text-faint hover:text-red-400"
                            @click="modal.image = null"
                        >
                            Remove
                        </button>
                    </div>
                </div>
                <label v-if="modal.connect?.kind === 'child'" class="block">
                    <span class="mb-1 block text-xs text-faint"
                        >Relationship to parent</span
                    >
                    <select
                        v-model="modal.parentType"
                        class="field text-sm capitalize"
                    >
                        <option
                            v-for="type in PARENT_TYPES"
                            :key="type"
                            :value="type"
                        >
                            {{ type }}
                        </option>
                    </select>
                </label>
                <div class="flex items-center justify-end gap-3 pt-1">
                    <button
                        class="text-sm text-faint hover:text-ink"
                        @click="closeModal"
                    >
                        Cancel
                    </button>
                    <button class="btn-primary" @click="submitModal">
                        {{ modal.mode === "edit" ? "Save" : "Add" }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
