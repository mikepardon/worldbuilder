<script setup>
// Renders a node's nested effect tree for a player: ALL groups list their effects, ANY groups offer a
// choice (radio) whose options are disabled until their prerequisite is met. Mutates the passed choices
// map in place and emits 'change' so the parent can persist. Recursive.
import EffectChoices from '@/Components/TalentWeb/EffectChoices.vue';
import { requirementRows, requirementsMet } from '@/lib/talentWeb';
import { computed } from 'vue';

const props = defineProps({
    node: { type: Object, required: true },
    choices: { type: Object, required: true },
    system: { type: Object, required: true },
    ctx: { type: Object, required: true },
    compendiumById: { type: Object, default: () => ({}) },
    readonly: { type: Boolean, default: false },
});
const emit = defineEmits(['change']);

const isGroup = computed(() => Array.isArray(props.node.children));
const met = (node) => !node.requires || requirementsMet(node.requires, props.ctx);
const chosenChild = (group) => group.children.find((child) => child && child.id === props.choices[group.id]);
const pick = (groupId, childId) => {
    props.choices[groupId] = childId;
    emit('change');
};

const statLabel = (key) => props.system.stats?.find((s) => s.key === key)?.label ?? key;
const resourceLabel = (key) => props.system.resources?.find((r) => r.key === key)?.label ?? key;
const skillLabel = (key) => props.system.skills?.find((s) => s.key === key)?.label ?? key;
const describeEffect = (effect) => {
    if (!effect || !effect.type) {
        return '';
    }
    const sign = (effect.delta ?? 0) >= 0 ? '+' : '';
    if (effect.type === 'stat') return `${sign}${effect.delta} ${statLabel(effect.key)}`;
    if (effect.type === 'resource') return `${effect.delta ? `${sign}${effect.delta} ` : ''}${resourceLabel(effect.key)}`;
    if (effect.type === 'skill') return effect.proficiency ? `${skillLabel(effect.key)} proficiency` : `${sign}${effect.delta} ${skillLabel(effect.key)}`;
    if (effect.type === 'spell' || effect.type === 'feat' || effect.type === 'ability') {
        return `Grants ${props.compendiumById[effect.item_id]?.name ?? 'an entry'}`;
    }
    if (effect.type === 'level_spell') {
        return `Raises ${props.compendiumById[effect.item_id]?.name ?? 'a spell'} to rank ${effect.to_level ?? '?'}`;
    }
    return `${sign}${effect.delta} ${effect.key}`;
};

const childLabel = (child) => child.label || (Array.isArray(child.children) ? 'A set of effects' : describeEffect(child.effect));
const requiresText = (node) =>
    node.requires ? requirementRows(node.requires, props.system, props.ctx).filter((row) => !row.group).map((row) => row.text).join(' · ') : '';
</script>

<template>
    <div>
        <!-- ANY: the player chooses one branch -->
        <div v-if="isGroup && node.op === 'any'" class="space-y-1">
            <p class="font-mono text-[10px] uppercase tracking-widest text-faint">{{ node.label || 'Choose one' }}</p>
            <label
                v-for="child in node.children"
                :key="child.id"
                class="flex items-baseline gap-2 rounded border px-2 py-1 text-xs"
                :class="[
                    choices[node.id] === child.id ? 'border-amber bg-amber/10' : 'border-edge2',
                    met(child) ? 'cursor-pointer' : 'opacity-50',
                ]"
            >
                <input type="radio" :checked="choices[node.id] === child.id" :disabled="readonly || !met(child)" @change="pick(node.id, child.id)" />
                <span class="flex-1 text-ink">{{ childLabel(child) }}</span>
                <span v-if="requiresText(child)" class="text-[10px]" :class="met(child) ? 'text-faint' : 'text-blood'">needs {{ requiresText(child) }}</span>
            </label>
            <EffectChoices
                v-if="chosenChild(node)"
                :node="chosenChild(node)"
                :choices="choices"
                :system="system"
                :ctx="ctx"
                :compendium-by-id="compendiumById"
                :readonly="readonly"
                class="ml-3 border-l border-edge2 pl-2"
                @change="emit('change')"
            />
        </div>

        <!-- ALL: every child applies -->
        <div v-else-if="isGroup" class="space-y-1">
            <EffectChoices
                v-for="child in node.children"
                :key="child.id"
                :node="child"
                :choices="choices"
                :system="system"
                :ctx="ctx"
                :compendium-by-id="compendiumById"
                :readonly="readonly"
                @change="emit('change')"
            />
        </div>

        <!-- leaf effect -->
        <div v-else class="flex items-baseline gap-2 text-xs">
            <span class="text-teal">{{ describeEffect(node.effect) }}</span>
            <span v-if="requiresText(node)" class="text-[10px]" :class="met(node) ? 'text-faint' : 'text-blood'">needs {{ requiresText(node) }}</span>
        </div>
    </div>
</template>
