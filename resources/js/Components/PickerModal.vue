<script setup>
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    title: { type: String, default: 'Select' },
    options: { type: Array, default: () => [] },
    // 'plain' → a comma-separated list (resistances, languages…); 'scored' → "Label +N" pairs (saves, skills).
    mode: { type: String, default: 'plain' },
    allowCustom: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'close']);

const signed = (n) => `${n >= 0 ? '+' : ''}${n}`;
const custom = ref('');

/* ---- plain mode ---- */
const selected = ref(
    props.mode === 'plain'
        ? (props.modelValue || '').split(',').map((s) => s.trim()).filter(Boolean)
        : [],
);
const isOn = (option) => selected.value.some((s) => s.toLowerCase() === option.toLowerCase());
const commitPlain = () => emit('update:modelValue', selected.value.join(', '));
const togglePlain = (option) => {
    const index = selected.value.findIndex((s) => s.toLowerCase() === option.toLowerCase());
    if (index >= 0) selected.value.splice(index, 1);
    else selected.value.push(option);
    commitPlain();
};
const removeChip = (value) => {
    selected.value = selected.value.filter((s) => s !== value);
    commitPlain();
};
const addCustom = () => {
    const value = custom.value.trim();
    if (value && !isOn(value)) {
        selected.value.push(value);
        commitPlain();
    }
    custom.value = '';
};

/* ---- scored mode ---- */
const scores = reactive({});
if (props.mode === 'scored') {
    (props.modelValue || '').split(',').map((s) => s.trim()).filter(Boolean).forEach((part) => {
        const match = part.match(/^(.+?)\s*([+-]?\d+)$/);
        if (match) scores[match[1].trim()] = Number(match[2]);
    });
}
const has = (option) => Object.prototype.hasOwnProperty.call(scores, option);
// The standard options plus any pre-existing entry that isn't one of them (so imports aren't dropped).
const scoredRows = computed(() => [...props.options, ...Object.keys(scores).filter((k) => !props.options.includes(k))]);
const commitScored = () => {
    const parts = scoredRows.value.filter(has).map((option) => `${option} ${signed(scores[option])}`);
    emit('update:modelValue', parts.join(', '));
};
const toggleScored = (option) => {
    if (has(option)) delete scores[option];
    else scores[option] = 0;
    commitScored();
};
const setBonus = (option, value) => {
    scores[option] = Number(value) || 0;
    commitScored();
};

const chips = computed(() => selected.value);
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-start justify-center bg-black/50 p-4 pt-24" @click.self="emit('close')">
        <div class="w-full max-w-md rounded-lg border border-edge2 bg-surface p-5 shadow-2xl">
            <div class="mb-3 flex items-center justify-between">
                <div class="font-display text-lg text-bright">{{ title }}</div>
                <button class="text-muted hover:text-ink" aria-label="Close" @click="emit('close')">✕</button>
            </div>

            <!-- Plain multi-select -->
            <template v-if="mode === 'plain'">
                <div v-if="chips.length" class="mb-3 flex flex-wrap gap-1.5">
                    <span v-for="c in chips" :key="c" class="flex items-center gap-1 rounded-full bg-raised px-2.5 py-0.5 text-xs capitalize text-ink">
                        {{ c }}
                        <button class="text-faint hover:text-blood" @click="removeChip(c)">✕</button>
                    </span>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="option in options"
                        :key="option"
                        class="rounded-full border px-3 py-1 text-xs capitalize transition"
                        :class="isOn(option) ? 'border-amber bg-amber/15 text-amber' : 'border-edge3 text-muted hover:text-ink'"
                        @click="togglePlain(option)"
                    >
                        {{ option }}
                    </button>
                </div>
                <div v-if="allowCustom" class="mt-3 flex gap-2">
                    <input v-model="custom" class="field !py-1.5 text-sm" placeholder="Add another…" @keydown.enter.prevent="addCustom" />
                    <button class="shrink-0 rounded-md border border-edge3 px-3 text-sm text-muted hover:text-ink" @click="addCustom">Add</button>
                </div>
            </template>

            <!-- Scored (checkbox + bonus) -->
            <template v-else>
                <div class="flex flex-col gap-1.5">
                    <div v-for="option in scoredRows" :key="option" class="flex items-center gap-3 rounded-md px-1 py-0.5">
                        <button
                            class="flex flex-1 items-center gap-2 text-left text-sm"
                            :class="has(option) ? 'text-ink' : 'text-muted'"
                            @click="toggleScored(option)"
                        >
                            <span class="flex h-4 w-4 items-center justify-center rounded border" :class="has(option) ? 'border-amber bg-amber text-night' : 'border-edge3'">
                                <span v-if="has(option)" class="text-[10px] leading-none">✓</span>
                            </span>
                            {{ option }}
                        </button>
                        <input
                            v-if="has(option)"
                            :value="scores[option]"
                            type="number"
                            class="field !w-20 !py-1 text-center text-sm"
                            @input="setBonus(option, $event.target.value)"
                        />
                    </div>
                </div>
            </template>

            <div class="mt-4 flex justify-end">
                <button class="btn-primary" @click="emit('close')">Done</button>
            </div>
        </div>
    </div>
</template>
