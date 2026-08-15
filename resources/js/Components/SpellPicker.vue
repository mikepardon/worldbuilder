<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    options: { type: Array, default: () => [] }, // {id, name, summary}
});
const emit = defineEmits(['add']);

const query = ref('');
const open = ref(false);

const matches = computed(() => {
    const q = query.value.trim().toLowerCase();
    const list = q ? props.options.filter((o) => o.name.toLowerCase().includes(q)) : props.options;
    return list.slice(0, 8);
});

const pick = (option) => {
    emit('add', { name: option.name, id: option.id });
    query.value = '';
    open.value = false;
};

// Enter adds an exact compendium match if there is one, otherwise the typed text as a custom spell.
const addTyped = () => {
    const name = query.value.trim();
    if (!name) return;
    const exact = props.options.find((o) => o.name.toLowerCase() === name.toLowerCase());
    emit('add', exact ? { name: exact.name, id: exact.id } : { name });
    query.value = '';
    open.value = false;
};
</script>

<template>
    <div class="relative">
        <input
            v-model="query"
            class="field !py-1 text-[13px]"
            placeholder="Add a spell — type it, or pick from the compendium"
            @focus="open = true"
            @input="open = true"
            @keydown.enter.prevent="addTyped"
            @keydown.esc="open = false"
            @blur="open = false"
        />
        <div v-if="open && matches.length" class="absolute z-30 mt-1 max-h-52 w-full overflow-auto rounded-md border border-edge2 bg-[#14161b] py-1 shadow-xl">
            <button
                v-for="o in matches"
                :key="o.id"
                type="button"
                class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-raised"
                @mousedown.prevent="pick(o)"
            >
                <span class="min-w-0 flex-1 truncate text-[13px] text-ink">{{ o.name }}</span>
                <span v-if="o.summary" class="hidden min-w-0 flex-[2] truncate text-[11px] text-faint sm:block">{{ o.summary }}</span>
            </button>
        </div>
    </div>
</template>
