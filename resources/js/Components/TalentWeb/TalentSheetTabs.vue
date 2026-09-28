<script setup>
import { marked } from 'marked';
import { computed, ref } from 'vue';

// The character-sheet tabs: the numeric skills grid, plus the spells/feats a character's talents (and
// race) grant, drawn straight from the linked compendium entries. Shared by the builder preview and the
// player's build page so both read the same shape.
const props = defineProps({
    system: { type: Object, required: true },
    // The computed sheet's skills map: { [key]: { value, proficient } }.
    skills: { type: Object, default: () => ({}) },
    // Granted compendium entries: [{ id, name, item_type, fields, summary, document, source }].
    grants: { type: Array, default: () => [] },
    // Leveled spells the character has learned: [{ item, cap, levels: [{ level, mana, name, min_level, available }] }].
    spellLevels: { type: Array, default: () => [] },
    // The level the character plays at, so a locked rank can say whether it needs a higher level or isn't learned yet.
    characterLevel: { type: Number, default: 1 },
});

// A learned spell's ranks, keyed by the spell's id (only spells authored with mana-costed ranks appear here).
const ranksById = computed(() => {
    const map = {};
    for (const entry of props.spellLevels) {
        map[entry.item.id] = entry;
    }
    return map;
});

// The meta line for a leveled spell: its rank span, mana span, and the highest rank the character has learned.
const spellCardMeta = (item) => {
    const entry = ranksById.value[item.id];
    if (!entry?.levels?.length) {
        return cardMeta(item);
    }
    const manas = entry.levels.map((rank) => Number(rank.mana ?? 0));
    const first = entry.levels[0].level;
    const last = entry.levels[entry.levels.length - 1].level;
    const rankLabel = first === last ? `L${first}` : `L${first}–L${last}`;
    return `${rankLabel} · ${Math.min(...manas)}–${Math.max(...manas)} mana · learned to L${entry.cap}`;
};

// Why a rank the character can't cast is locked: a level requirement it hasn't reached, or simply not learned yet.
const lockLabel = (rank) => (props.characterLevel < (rank.min_level ?? 0) ? `lvl ${rank.min_level}+` : 'not learned');

const activation = (item) => String(item.fields?.activation ?? '').toLowerCase();

const spells = computed(() => props.grants.filter((item) => item.item_type === 'spell'));
const abilities = computed(() => props.grants.filter((item) => item.item_type === 'ability'));
// Feats split between Actions (activatable) and Traits (passive) by their activation.
const feats = computed(() => props.grants.filter((item) => item.item_type === 'feat'));
const actions = computed(() => feats.value.filter((item) => activation(item) && activation(item) !== 'passive'));
const traits = computed(() => feats.value.filter((item) => !activation(item) || activation(item) === 'passive'));

const proficientCount = computed(() => (props.system.skills ?? []).filter((skill) => props.skills[skill.key]?.proficient).length);

const tabs = computed(() => [
    { key: 'skills', label: 'Skills', count: proficientCount.value },
    { key: 'spells', label: 'Spells', count: spells.value.length },
    { key: 'actions', label: 'Actions', count: actions.value.length },
    { key: 'traits', label: 'Traits', count: traits.value.length },
    { key: 'abilities', label: 'Abilities', count: abilities.value.length },
]);

const active = ref('skills');
const listFor = (key) => ({ spells: spells.value, actions: actions.value, traits: traits.value, abilities: abilities.value }[key] ?? []);

// A short meta line under a card's name: spell level + casting time, or a feat's activation + prerequisite.
const cardMeta = (item) => {
    const fields = item.fields ?? {};
    const parts =
        item.item_type === 'spell'
            ? [fields.level, fields.casting_time]
            : item.item_type === 'ability'
              ? [fields.activation, fields.uses]
              : [fields.activation, fields.prerequisite];
    return parts.filter(Boolean).join(' · ');
};


// The rendered body: the structured description first, else the stored document, else the summary.
const cardBody = (item) => {
    const description = item.fields?.description;
    if (description) {
        let markdown = description;
        if (item.fields?.higher_levels) {
            markdown += `\n\n***At Higher Levels.*** ${item.fields.higher_levels}`;
        }
        return marked.parse(markdown, { breaks: true });
    }
    if (item.document) {
        return marked.parse(item.document.replace(/^#{1,6}\s+.*\n+/, ''), { breaks: true });
    }
    return item.summary ? `<p>${item.summary}</p>` : '';
};
</script>

<template>
    <div>
        <div class="flex flex-wrap gap-1 border-b border-edge">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                class="rounded-t px-2.5 py-1 font-mono text-[10px] uppercase tracking-widest"
                :class="active === tab.key ? 'bg-amber/15 text-amber' : 'text-faint hover:text-ink'"
                @click="active = tab.key"
            >
                {{ tab.label }} <span class="text-[9px]">{{ tab.count }}</span>
            </button>
        </div>

        <!-- Skills: the numeric grid -->
        <div v-if="active === 'skills'" class="grid grid-cols-2 gap-x-3 gap-y-0.5 pt-2">
            <div
                v-for="skill in system.skills"
                :key="skill.key"
                class="flex items-baseline justify-between text-xs"
                :class="skills[skill.key]?.proficient ? 'text-ink' : 'text-faint'"
            >
                <span class="truncate">{{ skill.label }}</span>
                <span class="font-mono">{{ (skills[skill.key]?.value ?? 0) >= 0 ? '+' : '' }}{{ skills[skill.key]?.value ?? 0 }}</span>
            </div>
        </div>

        <!-- Spells / Actions / Traits: cards drawn from the linked compendium entries -->
        <div v-else class="space-y-2 pt-2">
            <p v-if="!listFor(active).length" class="text-[11px] text-faint">Nothing here yet — allocate talents that grant one.</p>
            <div v-for="item in listFor(active)" :key="`${active}-${item.id}`" class="rounded border border-edge2 bg-raised p-2">
                <div class="flex items-baseline gap-2">
                    <span class="min-w-0 flex-1 truncate font-display text-sm text-bright">{{ item.name }}</span>
                    <span v-if="(active === 'spells' ? spellCardMeta(item) : cardMeta(item))" class="shrink-0 font-mono text-[9px] uppercase tracking-wide text-faint">{{ active === 'spells' ? spellCardMeta(item) : cardMeta(item) }}</span>
                </div>
                <!-- A leveled spell lists the ranks the character can cast (with each rank's mana), plus the locked ones. -->
                <div v-if="active === 'spells' && ranksById[item.id]?.levels?.length" class="mt-1.5 space-y-0.5">
                    <div
                        v-for="rank in ranksById[item.id].levels"
                        :key="rank.level"
                        class="flex items-baseline gap-2 text-xs"
                        :class="rank.available ? 'text-ink' : 'text-faint opacity-70'"
                    >
                        <span class="w-6 shrink-0 font-mono text-[10px]">L{{ rank.level }}</span>
                        <span class="min-w-0 flex-1 truncate">{{ rank.name || item.name }}</span>
                        <span v-if="rank.available" class="shrink-0 font-mono text-[10px] text-amber">{{ rank.mana }} mana</span>
                        <span v-else class="shrink-0 font-mono text-[9px] uppercase tracking-wide text-faint">{{ lockLabel(rank) }}</span>
                    </div>
                </div>
                <div v-else class="talent-card-body mt-1 text-xs text-muted" v-html="cardBody(item)" />
            </div>
        </div>
    </div>
</template>

<style scoped>
.talent-card-body :deep(p) {
    margin: 0 0 4px;
}
.talent-card-body :deep(ul) {
    margin: 0 0 4px;
    padding-left: 16px;
    list-style: disc;
}
.talent-card-body :deep(strong) {
    color: #e8e3d9;
}
</style>
