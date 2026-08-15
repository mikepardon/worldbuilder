<script setup>
import DicePopover from "@/Components/DicePopover.vue";
import SpellPopover from "@/Components/SpellPopover.vue";
import { useDiceRoller } from "@/composables/useDiceRoller";
import { useSpellRefs } from "@/composables/useSpellRefs";
import { wrapDice } from "@/lib/dice";
import {
    abilityModifier as mod,
    escapeHtml,
    spellcasting,
    statblockLines,
} from "@/lib/statblock";
import { computed, toRef } from "vue";

const props = defineProps({
    block: Object,
    name: String,
    // Optional portrait shown top-left of the card.
    image: { type: String, default: "" },
    // Spells ({id, name, summary}) so a spellcasting entry's linked spells can link + hover-preview.
    spells: { type: Array, default: () => [] },
    // Preview affordances mirroring the {{monster,wide}} / {{monster,frame}} embed layout classes.
    wide: { type: Boolean, default: false },
    framed: { type: Boolean, default: false },
});

const abilities = computed(() => props.block?.abilities ?? {});
const lines = computed(() => statblockLines(props.block));
const spellcastingView = computed(() => spellcasting(props.block));
const spellById = computed(() => new Map(props.spells.map((s) => [s.id, s])));
const spellInfo = (id) => (id == null ? undefined : spellById.value.get(id));
const diceHtml = (text) => wrapDice(escapeHtml(text));
const abilityRoll = (score) => {
    const modifier = Math.floor((Number(score ?? 10) - 10) / 2);
    return `1d20${modifier >= 0 ? "+" : ""}${modifier}`;
};

const { roll, handleClick: diceClick } = useDiceRoller();
const {
    spell,
    handleClick: spellClick,
    handleOver: spellOver,
    handleOut: spellOut,
} = useSpellRefs(toRef(props, "spells"));
const handleClick = (event) => {
    if (!spellClick(event)) diceClick(event);
};
const GROUPS = {
    traits: "Traits",
    actions: "Actions",
    bonusActions: "Bonus Actions",
    reactions: "Reactions",
    legendary: "Legendary Actions",
    mythic: "Mythic Actions",
};
</script>

<template>
    <div
        class="rounded-md bg-[#fdf1dc] p-5 font-serif text-[#3a2c14] shadow"
        :class="[
            framed ? 'sb-framed' : 'border border-amber-900/20',
            wide ? 'sb-wide' : '',
        ]"
        @click="handleClick"
        @mouseover="spellOver"
        @mouseout="spellOut"
    >
        <div class="flex items-start gap-3">
            <img
                v-if="image"
                :src="image"
                :alt="name"
                class="h-20 w-20 shrink-0 rounded-md object-cover ring-1 ring-amber-900/30"
            />
            <div class="min-w-0">
                <h3 class="font-serif text-2xl text-[#7a200c]">{{ name }}</h3>
                <div class="text-sm italic">
                    {{ block.size }} {{ block.type
                    }}<span v-if="block.alignment"
                        >, {{ block.alignment }}</span
                    >
                </div>
            </div>
        </div>
        <div class="my-2 h-px bg-[#7a200c]/40" />

        <div class="text-sm">
            <div>
                <strong>Armor Class</strong>
                <span v-html="diceHtml(block.ac)" />
            </div>
            <div>
                <strong>Hit Points</strong> <span v-html="diceHtml(block.hp)" />
            </div>
            <div>
                <strong>Speed</strong> <span v-html="diceHtml(block.speed)" />
            </div>
        </div>
        <div class="my-2 h-px bg-[#7a200c]/40" />

        <table class="w-full text-center text-sm">
            <thead>
                <tr class="text-[#7a200c]">
                    <th>STR</th>
                    <th>DEX</th>
                    <th>CON</th>
                    <th>INT</th>
                    <th>WIS</th>
                    <th>CHA</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td
                        v-for="a in ['str', 'dex', 'con', 'int', 'wis', 'cha']"
                        :key="a"
                    >
                        <span
                            class="dice-roll"
                            role="button"
                            tabindex="0"
                            :data-roll="abilityRoll(abilities[a])"
                            data-min="1"
                            :data-label="`${a.toUpperCase()} check`"
                            >{{ mod(abilities[a]) }}</span
                        >
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="my-2 h-px bg-[#7a200c]/40" />

        <div class="text-sm">
            <div v-for="[label, value] in lines" :key="label">
                <strong>{{ label }}</strong> <span v-html="diceHtml(value)" />
            </div>
        </div>

        <template v-for="(group, key) in GROUPS" :key="key">
            <div
                v-if="
                    (block[key] && block[key].length) ||
                    (key === 'traits' && spellcastingView)
                "
                class="mt-3"
            >
                <div
                    class="border-b border-[#7a200c]/40 font-serif text-lg text-[#7a200c]"
                >
                    {{ group }}
                </div>
                <template v-if="key === 'traits' && spellcastingView">
                    <p class="mt-1 text-sm">
                        <strong><em>Spellcasting.</em></strong>
                        {{ spellcastingView.intro }}
                    </p>
                    <p
                        v-for="(g, gi) in spellcastingView.groups"
                        :key="gi"
                        class="mt-1 text-sm"
                    >
                        <strong v-if="g.label">{{ g.label }}:</strong>
                        <template v-for="(sp, ni) in g.spells" :key="ni"
                            ><span
                                v-if="spellInfo(sp.id)"
                                class="spell-ref"
                                role="button"
                                tabindex="0"
                                :data-spell-id="sp.id"
                                >{{ sp.name }}</span
                            ><em v-else>{{ sp.name }}</em
                            >{{
                                ni < g.spells.length - 1 ? ", " : ""
                            }}</template
                        >
                    </p>
                </template>
                <p
                    v-for="(e, i) in block[key] || []"
                    :key="i"
                    class="mt-1 text-sm"
                >
                    <strong
                        ><em>{{ e.name }}.</em></strong
                    >
                    <span v-html="diceHtml(e.desc)" />
                </p>
            </div>
        </template>

        <DicePopover :roll="roll" />
        <SpellPopover :spell="spell" />
    </div>
</template>

<style scoped>
/* {{monster,wide}} — the stat block flows into two columns at double width. */
.sb-wide {
    column-count: 2;
    column-gap: 1.6rem;
}
.sb-wide > * {
    break-inside: avoid-column;
}
/* {{monster,frame}} — a decorative gilt double border. */
.sb-framed {
    border: 3px solid #b89b3f;
    box-shadow:
        inset 0 0 0 2px #fdf1dc,
        inset 0 0 0 4px #b89b3f,
        0 3px 12px rgba(0, 0, 0, 0.45);
}
/* Compendium-linked spells in a spellcasting entry. */
.spell-ref {
    color: #7a200c;
    text-decoration: none;
    border-bottom: 1px dotted currentColor;
    cursor: pointer;
    font-style: italic;
}
.spell-ref:hover {
    background: rgba(122, 32, 12, 0.08);
}

/* Clickable dice expressions and ability checks. :deep so v-html-injected spans are styled too. */
.dice-roll,
:deep(.dice-roll) {
    cursor: pointer;
    font-weight: 700;
    border-bottom: 1px dotted currentColor;
}
.dice-roll:hover,
:deep(.dice-roll:hover) {
    color: #922610;
    background: rgba(146, 38, 16, 0.1);
    border-radius: 3px;
}
</style>
