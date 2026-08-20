<script setup>
import { captureError } from "@/monitoring";
import { ref } from "vue";

const props = defineProps({
    campaignId: { type: [Number, String], required: true },
    facts: { type: Array, default: () => [] },
    instructions: { type: Array, default: () => [] },
});
const emit = defineEmits(["updated"]);

const editing = ref(false);
const saving = ref(false);
const error = ref("");
const draftFacts = ref([]);
const draftInstructions = ref([]);

function startEdit() {
    draftFacts.value = [...props.facts];
    draftInstructions.value = [...props.instructions];
    error.value = "";
    editing.value = true;
}

async function save() {
    saving.value = true;
    error.value = "";
    try {
        const res = await window.axios.put(
            route("campaigns.recap-guidance", props.campaignId),
            { facts: draftFacts.value, instructions: draftInstructions.value },
        );
        emit("updated", res.data);
        editing.value = false;
    } catch (e) {
        captureError(e);
        error.value =
            e?.response?.data?.message ||
            "Couldn’t save the guidance — please try again.";
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <section class="panel space-y-3 p-4">
        <div class="flex items-start justify-between gap-2">
            <div>
                <h2 class="font-display text-lg text-bright">
                    Campaign recap guidance
                </h2>
                <p class="text-xs text-faint">
                    Applies to every recap in this campaign. Save, then
                    Re-analyse to apply.
                </p>
            </div>
            <button
                v-if="!editing"
                class="shrink-0 rounded border border-edge3 px-2.5 py-1 text-xs text-muted hover:text-ink"
                @click="startEdit"
            >
                Edit
            </button>
            <div v-else class="flex shrink-0 gap-2">
                <button
                    class="rounded bg-amber/90 px-2.5 py-1 text-xs font-medium text-night hover:bg-amber disabled:opacity-60"
                    :disabled="saving"
                    @click="save"
                >
                    {{ saving ? "Saving…" : "Save" }}
                </button>
                <button
                    class="text-xs text-faint hover:text-ink"
                    @click="editing = false"
                >
                    Cancel
                </button>
            </div>
        </div>

        <!-- Read-only -->
        <div v-if="!editing" class="grid gap-4 sm:grid-cols-2">
            <div>
                <h3 class="mb-1 text-xs uppercase tracking-wide text-faint">
                    Facts &amp; corrections
                </h3>
                <ul v-if="facts.length" class="space-y-1">
                    <li
                        v-for="(f, i) in facts"
                        :key="i"
                        class="flex gap-2 text-sm text-muted"
                    >
                        <span class="text-faint">•</span><span>{{ f }}</span>
                    </li>
                </ul>
                <p v-else class="text-xs text-faint">None yet.</p>
            </div>
            <div>
                <h3 class="mb-1 text-xs uppercase tracking-wide text-faint">
                    Instructions
                </h3>
                <ul v-if="instructions.length" class="space-y-1">
                    <li
                        v-for="(ins, i) in instructions"
                        :key="i"
                        class="flex gap-2 text-sm text-muted"
                    >
                        <span class="text-faint">•</span><span>{{ ins }}</span>
                    </li>
                </ul>
                <p v-else class="text-xs text-faint">None yet.</p>
            </div>
        </div>

        <!-- Edit -->
        <div v-else class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs uppercase tracking-wide text-faint">
                        Facts &amp; corrections
                    </h3>
                    <button
                        class="text-xs text-amber hover:underline"
                        @click="draftFacts.push('')"
                    >
                        + Add
                    </button>
                </div>
                <div
                    v-for="(f, i) in draftFacts"
                    :key="i"
                    class="flex items-center gap-2"
                >
                    <input
                        v-model="draftFacts[i]"
                        type="text"
                        placeholder="e.g. “Brian” said in person = the character Clayton"
                        class="w-full rounded border border-edge2 bg-night/60 p-2 text-sm text-ink"
                    />
                    <button
                        class="shrink-0 text-xs text-faint hover:text-amber"
                        @click="draftFacts.splice(i, 1)"
                    >
                        Remove
                    </button>
                </div>
                <p v-if="!draftFacts.length" class="text-xs text-faint">
                    No facts yet — add one above.
                </p>
            </div>
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs uppercase tracking-wide text-faint">
                        Instructions
                    </h3>
                    <button
                        class="text-xs text-amber hover:underline"
                        @click="draftInstructions.push('')"
                    >
                        + Add
                    </button>
                </div>
                <div
                    v-for="(ins, i) in draftInstructions"
                    :key="i"
                    class="flex items-center gap-2"
                >
                    <input
                        v-model="draftInstructions[i]"
                        type="text"
                        placeholder="e.g. Never mention dice rolls in the recap"
                        class="w-full rounded border border-edge2 bg-night/60 p-2 text-sm text-ink"
                    />
                    <button
                        class="shrink-0 text-xs text-faint hover:text-amber"
                        @click="draftInstructions.splice(i, 1)"
                    >
                        Remove
                    </button>
                </div>
                <p v-if="!draftInstructions.length" class="text-xs text-faint">
                    No instructions yet — add one above.
                </p>
            </div>
        </div>

        <p v-if="error" class="text-xs text-amber">{{ error }}</p>
    </section>
</template>
