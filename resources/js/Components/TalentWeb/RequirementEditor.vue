<script setup>
// A nested AND/OR requirements builder. Each level is a group that matches ALL or ANY of its children;
// a child is either a leaf rule (a stat minimum, or a count of nodes of a kind) or another group. It
// mutates the passed group object in place, so the node editor's autosave picks the change up.
import RequirementEditor from '@/Components/TalentWeb/RequirementEditor.vue';
import SpellPicker from '@/Components/SpellPicker.vue';
import { computed } from 'vue';

const props = defineProps({
    group: { type: Object, required: true },
    system: { type: Object, required: true },
    // Spells/feats/abilities, for a "has this entry" requirement.
    compendium: { type: Array, default: () => [] },
    depth: { type: Number, default: 0 },
});

const kinds = computed(() => props.system.nodeKinds ?? []);
const stats = computed(() => props.system.stats ?? []);
const compendiumById = computed(() => {
    const map = {};
    for (const item of props.compendium) {
        map[item.id] = item;
    }
    return map;
});
const grantName = (id) => compendiumById.value[id]?.name ?? 'entry…';

const isGroup = (child) => child && child.op && Array.isArray(child.children);
const leafType = (child) => (child.type === 'stat' || child.type === 'grant' ? child.type : 'nodes');
const addRule = () => props.group.children.push({ type: 'nodes', count: 1, kind: '' });
const addGroup = () => props.group.children.push({ op: 'all', children: [{ type: 'nodes', count: 1, kind: '' }] });
const removeChild = (index) => props.group.children.splice(index, 1);

// Switching a leaf's kind resets the fields that don't apply, so it never carries stale data.
const setLeafType = (child, type) => {
    delete child.count;
    delete child.kind;
    delete child.min_ring;
    delete child.key;
    delete child.min;
    delete child.item_id;
    if (type === 'stat') {
        child.type = 'stat';
        child.key = stats.value[0]?.key ?? '';
        child.min = 1;
    } else if (type === 'grant') {
        child.type = 'grant';
        child.item_id = null;
    } else {
        child.type = 'nodes';
        child.count = 1;
        child.kind = '';
    }
};
</script>

<template>
    <div class="space-y-1.5 rounded border border-edge2 p-2" :class="depth ? 'bg-night/40' : ''">
        <div class="flex items-center gap-2">
            <span class="text-[10px] text-faint">Match</span>
            <select :value="group.op" class="field !w-24 !py-0.5 text-[11px]" @change="group.op = $event.target.value">
                <option value="all">ALL of</option>
                <option value="any">ANY of</option>
            </select>
        </div>

        <div v-for="(child, index) in group.children" :key="index" class="flex items-start gap-1">
            <div class="min-w-0 flex-1">
                <RequirementEditor v-if="isGroup(child)" :group="child" :system="system" :compendium="compendium" :depth="depth + 1" />
                <div v-else class="grid grid-cols-12 gap-1">
                    <select
                        :value="leafType(child)"
                        class="field col-span-4 !py-1 text-[11px]"
                        @change="setLeafType(child, $event.target.value)"
                    >
                        <option value="nodes">nodes</option>
                        <option value="stat">stat</option>
                        <option value="grant">has entry</option>
                    </select>
                    <template v-if="child.type === 'stat'">
                        <select v-model="child.key" class="field col-span-5 !py-1 text-[11px]">
                            <option v-for="stat in stats" :key="stat.key" :value="stat.key">{{ stat.label || stat.key }}</option>
                        </select>
                        <input v-model.number="child.min" type="number" placeholder="min" class="field col-span-3 !py-1 text-[11px]" />
                    </template>
                    <div v-else-if="child.type === 'grant'" class="col-span-8">
                        <div v-if="child.item_id" class="flex items-center justify-between rounded border border-edge2 px-2 py-1 text-[11px]">
                            <span class="min-w-0 truncate text-ink">{{ grantName(child.item_id) }}</span>
                            <button class="shrink-0 text-faint hover:text-blood" @click="child.item_id = null">✕</button>
                        </div>
                        <SpellPicker v-else :options="compendium" compendium-only placeholder="Pick an entry…" @add="(picked) => picked.id && (child.item_id = picked.id)" />
                    </div>
                    <template v-else>
                        <input v-model.number="child.count" type="number" min="1" placeholder="#" class="field col-span-2 !py-1 text-[11px]" />
                        <select v-model="child.kind" class="field col-span-6 !py-1 text-[11px]">
                            <option value="">any kind</option>
                            <option v-for="kind in kinds" :key="kind.key" :value="kind.key">{{ kind.label || kind.key }}</option>
                        </select>
                        <input v-model.number="child.min_ring" type="number" placeholder="ring+" class="field col-span-2 !py-1 text-[11px]" />
                    </template>
                </div>
            </div>
            <button class="pt-1 text-xs text-faint hover:text-blood" @click="removeChild(index)">✕</button>
        </div>

        <div class="flex gap-3 text-[11px]">
            <button class="text-amber" @click="addRule">+ rule</button>
            <button class="text-teal" @click="addGroup">+ group</button>
        </div>
    </div>
</template>
