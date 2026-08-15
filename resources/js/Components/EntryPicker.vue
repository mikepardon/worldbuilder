<script setup>
import { computed, ref } from "vue";

const props = defineProps({
    // Single: an id (or null). Multiple: an array of ids.
    modelValue: { type: [Number, String, Array, null], default: null },
    entries: { type: Array, default: () => [] }, // [{ id, title, kind, kindLabel }]
    placeholder: { type: String, default: "Search entries…" },
    multiple: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue"]);

const query = ref("");
const open = ref(false);

const ids = computed(() =>
    props.multiple
        ? Array.isArray(props.modelValue)
            ? props.modelValue
            : []
        : [],
);
const selected = computed(() =>
    props.multiple
        ? ids.value
              .map((id) => props.entries.find((e) => e.id === id))
              .filter(Boolean)
        : (props.entries.find((e) => e.id === props.modelValue) ?? null),
);

const filtered = computed(() => {
    const t = query.value.trim().toLowerCase();
    const chosen = new Set(ids.value);
    const rows = props.entries.filter(
        (e) =>
            (!props.multiple || !chosen.has(e.id)) &&
            (!t || e.title.toLowerCase().includes(t)),
    );
    return rows.slice(0, 8);
});

const choose = (entry) => {
    if (props.multiple) {
        emit("update:modelValue", [...ids.value, entry.id]);
    } else {
        emit("update:modelValue", entry.id);
        open.value = false;
    }
    query.value = "";
};
const removeOne = (id) =>
    emit(
        "update:modelValue",
        ids.value.filter((x) => x !== id),
    );
const clear = () => emit("update:modelValue", null);
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <!-- Multiple: chips of the chosen entries -->
        <div v-if="multiple && selected.length" class="flex flex-wrap gap-1.5">
            <span
                v-for="e in selected"
                :key="e.id"
                class="flex items-center gap-1.5 rounded-full border border-edge2 bg-[#1a1d24] px-2.5 py-0.5 text-[12.5px] text-ink"
            >
                <span class="font-mono text-[9px] uppercase text-teal">{{
                    e.kindLabel || e.kind
                }}</span>
                {{ e.title }}
                <button
                    type="button"
                    class="text-faint hover:text-blood"
                    @click="removeOne(e.id)"
                >
                    ✕
                </button>
            </span>
        </div>

        <!-- Single: the chosen entry -->
        <div
            v-if="!multiple && selected"
            class="flex items-center gap-2 rounded-[6px] border border-edge2 bg-[#1a1d24] px-2.5 py-1.5"
        >
            <span
                class="font-mono text-[9px] uppercase tracking-[0.1em] text-teal"
                >{{ selected.kindLabel || selected.kind }}</span
            >
            <span class="min-w-0 flex-1 truncate text-[13.5px] text-ink">{{
                selected.title
            }}</span>
            <button
                type="button"
                class="text-faint hover:text-blood"
                title="Clear"
                @click="clear"
            >
                ✕
            </button>
        </div>

        <!-- Search + dropdown (always shown for multiple; when empty for single) -->
        <div v-if="multiple || !selected" class="relative">
            <input
                v-model="query"
                :placeholder="placeholder"
                class="field !py-2 text-[13.5px]"
                @focus="open = true"
                @blur="open = false"
            />
            <div
                v-if="open && filtered.length"
                class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-[7px] border border-edge2 bg-[#14161b] shadow-xl"
            >
                <button
                    v-for="e in filtered"
                    :key="e.id"
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-[#1a1d24]"
                    @mousedown.prevent="choose(e)"
                >
                    <span
                        class="font-mono text-[9px] uppercase tracking-[0.1em] text-faint"
                        >{{ e.kindLabel || e.kind }}</span
                    >
                    <span
                        class="min-w-0 flex-1 truncate text-[13.5px] text-ink"
                        >{{ e.title }}</span
                    >
                </button>
            </div>
            <div
                v-else-if="open && query.trim()"
                class="absolute z-20 mt-1 w-full rounded-[7px] border border-edge2 bg-[#14161b] px-3 py-2 text-[13px] text-faint shadow-xl"
            >
                No entries match.
            </div>
        </div>
    </div>
</template>
