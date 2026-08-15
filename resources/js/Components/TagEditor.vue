<script setup>
import { ref } from 'vue';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    suggestions: { type: Array, default: () => [] },
    listId: { type: String, default: 'tag-suggestions' },
});
const emit = defineEmits(['update:modelValue']);

const input = ref('');

const add = () => {
    const t = input.value.trim().toLowerCase();
    if (t && !props.modelValue.includes(t)) emit('update:modelValue', [...props.modelValue, t]);
    input.value = '';
};
const remove = (t) => emit('update:modelValue', props.modelValue.filter((x) => x !== t));
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5">
        <span v-for="t in modelValue" :key="t" class="flex items-center gap-1 rounded-full bg-raised px-2.5 py-0.5 text-xs text-muted">
            {{ t }}
            <button type="button" class="text-faint hover:text-blood" @click="remove(t)">✕</button>
        </span>
        <input
            v-model="input"
            :list="listId"
            class="field !w-32 !py-1 text-sm"
            placeholder="add tag…"
            @keydown.enter.prevent="add"
            @keydown.,.prevent="add"
        />
        <datalist :id="listId">
            <option v-for="t in suggestions" :key="t" :value="t" />
        </datalist>
    </div>
</template>
