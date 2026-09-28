<script setup>
// A nested AND/OR tree of a node's benefits. A group applies ALL of its children, or (ANY) exactly one
// the player chooses; a leaf is a single effect. Any node can carry a prerequisite. Mutates the passed
// node object in place, so the node editor's autosave picks it up. Recursive.
import EffectGroupEditor from '@/Components/TalentWeb/EffectGroupEditor.vue';
import RequirementEditor from '@/Components/TalentWeb/RequirementEditor.vue';
import SpellPicker from '@/Components/SpellPicker.vue';
import { computed } from 'vue';

const props = defineProps({
    node: { type: Object, required: true },
    system: { type: Object, required: true },
    compendium: { type: Array, default: () => [] },
    depth: { type: Number, default: 0 },
    isRoot: { type: Boolean, default: false },
});
const emit = defineEmits(['remove']);

const uid = () => Math.random().toString(36).slice(2, 8);
const newLeaf = () => ({ id: uid(), effect: { type: 'stat', key: '', delta: 1 } });
const newGroup = (op = 'all') => ({ id: uid(), op, children: [newLeaf()] });

const isGroup = computed(() => Array.isArray(props.node.children));

const addLeaf = () => props.node.children.push(newLeaf());
const addGroup = () => props.node.children.push(newGroup('all'));
const removeChild = (index) => props.node.children.splice(index, 1);

const hasRequires = computed(() => Boolean(props.node.requires && Array.isArray(props.node.requires.children)));
const toggleRequires = () => {
    if (hasRequires.value) {
        delete props.node.requires;
    } else {
        props.node.requires = { op: 'all', children: [{ type: 'nodes', count: 1, kind: '' }] };
    }
};

// --- leaf effect editing (mirrors EffectListEditor's row) ---
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
</script>

<template>
    <div class="space-y-1.5 rounded border border-edge2 p-2" :class="depth ? 'bg-night/40' : ''">
        <!-- Group -->
        <template v-if="isGroup">
            <div class="flex items-center gap-2">
                <span class="text-[10px] text-faint">Apply</span>
                <select v-model="node.op" class="field !w-24 !py-0.5 text-[11px]">
                    <option value="all">ALL of</option>
                    <option value="any">ANY of</option>
                </select>
                <input v-model="node.label" placeholder="label (optional)" class="field !py-0.5 text-[11px]" />
                <button class="text-[11px]" :class="hasRequires ? 'text-amber' : 'text-faint hover:text-teal'" @click="toggleRequires">prereq</button>
                <button v-if="!isRoot" class="text-xs text-faint hover:text-blood" @click="emit('remove')">✕</button>
            </div>
            <div v-if="hasRequires" class="pl-2">
                <RequirementEditor :group="node.requires" :system="system" :compendium="compendium" />
            </div>
            <EffectGroupEditor
                v-for="(child, index) in node.children"
                :key="child.id ?? index"
                :node="child"
                :system="system"
                :compendium="compendium"
                :depth="depth + 1"
                @remove="removeChild(index)"
            />
            <div class="flex gap-3 text-[11px]">
                <button class="text-teal" @click="addLeaf">+ effect</button>
                <button class="text-teal" @click="addGroup">+ group</button>
            </div>
        </template>

        <!-- Leaf -->
        <template v-else>
            <div class="flex items-center gap-1">
                <input v-model="node.label" placeholder="label (optional)" class="field !py-0.5 text-[11px]" />
                <button class="shrink-0 text-[11px]" :class="hasRequires ? 'text-amber' : 'text-faint hover:text-teal'" @click="toggleRequires">prereq</button>
                <button class="shrink-0 text-xs text-faint hover:text-blood" @click="emit('remove')">✕</button>
            </div>
            <div class="grid grid-cols-12 items-center gap-1">
                <select v-model="node.effect.type" class="field col-span-4 !py-1 text-xs">
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
                <template v-if="isGrantType(node.effect.type)">
                    <div class="col-span-8">
                        <div v-if="node.effect.item_id" class="flex items-center justify-between rounded border border-edge2 px-2 py-1 text-xs">
                            <span class="min-w-0 truncate text-ink">{{ grantName(node.effect.item_id) }}</span>
                            <button class="shrink-0 text-faint hover:text-blood" @click="node.effect.item_id = null">✕</button>
                        </div>
                        <SpellPicker
                            v-else
                            :options="compendiumOfType(node.effect.type)"
                            compendium-only
                            :placeholder="`Pick a ${node.effect.type}…`"
                            @add="(picked) => picked.id && (node.effect.item_id = picked.id)"
                        />
                    </div>
                </template>
                <template v-else-if="node.effect.type === 'level_spell'">
                    <div class="col-span-5">
                        <div v-if="node.effect.item_id" class="flex items-center justify-between rounded border border-edge2 px-2 py-1 text-xs">
                            <span class="min-w-0 truncate text-ink">{{ grantName(node.effect.item_id) }}</span>
                            <button class="shrink-0 text-faint hover:text-blood" @click="node.effect.item_id = null">✕</button>
                        </div>
                        <SpellPicker v-else :options="compendiumOfType('spell')" compendium-only placeholder="Pick a spell…" @add="(picked) => picked.id && (node.effect.item_id = picked.id)" />
                    </div>
                    <input v-model.number="node.effect.to_level" type="number" min="1" placeholder="→ rank" class="field col-span-3 !py-1 text-xs" title="Raise the learned spell to this rank" />
                </template>
                <template v-else>
                    <select v-if="node.effect.type !== 'derived'" v-model="node.effect.key" class="field col-span-5 !py-1 text-xs">
                        <option value="">key…</option>
                        <option v-for="key in keyOptions(node.effect.type)" :key="key" :value="key">{{ key }}</option>
                    </select>
                    <input v-else v-model="node.effect.key" placeholder="e.g. initiative" class="field col-span-5 !py-1 text-xs" />
                    <input v-model.number="node.effect.delta" type="number" placeholder="Δ" class="field col-span-3 !py-1 text-xs" />
                </template>
            </div>
            <div v-if="hasRequires" class="pl-2">
                <RequirementEditor :group="node.requires" :system="system" :compendium="compendium" />
            </div>
        </template>
    </div>
</template>
