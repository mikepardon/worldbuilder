<script setup>
// A list of node effects — the sheet modifiers (stat/resource/skill/derived) and the compendium grants
// (spell/feat/ability). Mutates the passed array in place, so the node editor's autosave picks it up.
// Reused for a node's always-applied effects and for each choose-one variant's effects.
import SpellPicker from '@/Components/SpellPicker.vue';
import { computed } from 'vue';

const props = defineProps({
    effects: { type: Array, required: true },
    system: { type: Object, required: true },
    compendium: { type: Array, default: () => [] },
});

const GRANT_TYPES = ['spell', 'feat', 'ability'];
const isGrantType = (type) => GRANT_TYPES.includes(type);
const compendiumById = computed(() => {
    const map = {};
    for (const item of props.compendium) {
        map[item.id] = item;
    }
    return map;
});
const compendiumOfType = (type) => props.compendium.filter((item) => item.item_type === type);
const grantName = (id) => compendiumById.value[id]?.name ?? `#${id}`;
const setGrant = (effect, event) => {
    if (event.id) {
        effect.item_id = event.id;
    }
};

const keyOptions = (type) => {
    if (type === 'stat') {
        return (props.system.stats ?? []).map((stat) => stat.key).filter(Boolean);
    }
    if (type === 'resource') {
        return (props.system.resources ?? []).map((resource) => resource.key).filter(Boolean);
    }
    if (type === 'skill') {
        return (props.system.skills ?? []).map((skill) => skill.key).filter(Boolean);
    }
    return [];
};

const addEffect = () => props.effects.push({ type: 'stat', key: '', delta: 1 });
const removeEffect = (index) => props.effects.splice(index, 1);
</script>

<template>
    <div>
        <div class="mb-1 flex justify-end">
            <button class="text-xs text-teal" @click="addEffect">+ effect</button>
        </div>
        <div v-for="(effect, index) in effects" :key="index" class="mb-1 grid grid-cols-12 items-center gap-1">
            <select v-model="effect.type" class="field col-span-4 !py-1 text-xs">
                <option value="stat">stat</option>
                <option value="resource">resource</option>
                <option value="skill">skill</option>
                <option value="derived">derived</option>
                <template v-if="compendium.length">
                    <option value="spell">spell</option>
                    <option value="feat">feat</option>
                    <option value="ability">ability</option>
                    <option value="level_spell">level spell</option>
                </template>
            </select>
            <template v-if="isGrantType(effect.type)">
                <div class="col-span-7">
                    <div v-if="effect.item_id" class="flex items-center justify-between rounded border border-edge2 px-2 py-1 text-xs">
                        <span class="min-w-0 truncate text-ink">{{ grantName(effect.item_id) }}</span>
                        <button class="shrink-0 text-faint hover:text-blood" @click="effect.item_id = null">✕</button>
                    </div>
                    <SpellPicker
                        v-else
                        :options="compendiumOfType(effect.type)"
                        compendium-only
                        :placeholder="`Pick a ${effect.type}…`"
                        @add="(picked) => setGrant(effect, picked)"
                    />
                </div>
            </template>
            <template v-else-if="effect.type === 'level_spell'">
                <div class="col-span-4">
                    <div v-if="effect.item_id" class="flex items-center justify-between rounded border border-edge2 px-2 py-1 text-xs">
                        <span class="min-w-0 truncate text-ink">{{ grantName(effect.item_id) }}</span>
                        <button class="shrink-0 text-faint hover:text-blood" @click="effect.item_id = null">✕</button>
                    </div>
                    <SpellPicker v-else :options="compendiumOfType('spell')" compendium-only placeholder="Pick a spell…" @add="(picked) => setGrant(effect, picked)" />
                </div>
                <input v-model.number="effect.to_level" type="number" min="1" placeholder="→ rank" class="field col-span-3 !py-1 text-xs" title="Raise the learned spell to this rank" />
            </template>
            <template v-else>
                <select v-if="effect.type !== 'derived'" v-model="effect.key" class="field col-span-4 !py-1 text-xs">
                    <option value="">key…</option>
                    <option v-for="key in keyOptions(effect.type)" :key="key" :value="key">{{ key }}</option>
                </select>
                <input v-else v-model="effect.key" placeholder="e.g. initiative" class="field col-span-4 !py-1 text-xs" />
                <input v-model.number="effect.delta" type="number" placeholder="Δ" class="field col-span-3 !py-1 text-xs" />
            </template>
            <button class="col-span-1 text-xs text-faint hover:text-blood" @click="removeEffect(index)">✕</button>
        </div>
        <p v-if="!compendium.length" class="text-[11px] text-faint">Add spells, feats or abilities to this world's compendium to grant them here.</p>
    </div>
</template>
