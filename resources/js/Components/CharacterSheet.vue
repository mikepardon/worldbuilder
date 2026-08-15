<script setup>
import { computed, reactive, ref, watch } from "vue";

const props = defineProps({
    name: String,
    image: { type: String, default: "" },
    ac: { type: Number, default: null },
    hp: { type: Number, default: null },
    maxHp: { type: Number, default: null },
    tempHp: { type: Number, default: 0 },
    deathSuccess: { type: Number, default: 0 },
    deathFail: { type: Number, default: 0 },
    exhaustion: { type: Number, default: 0 },
    concentratingOn: { type: String, default: "" },
    slotsUsed: { type: Object, default: () => ({}) },
    hitDiceUsed: { type: Object, default: () => ({}) },
    // Player edits layered over the D&D Beyond base: { prepared, equipped, attuned, currency }.
    sheetState: { type: Object, default: () => ({}) },
    notes: { type: String, default: "" },
    canEdit: { type: Boolean, default: false },
    character: { type: Object, default: () => ({}) },
});
const emit = defineEmits(["update", "spend-hit-dice"]);
const clamp = (v, min, max) => Math.min(max, Math.max(min, v));
// Split an extracted description into paragraphs so it lays out with sane spacing (the source uses
// blank lines between block elements, which whitespace-pre-line would blow out into large gaps).
const paras = (text) =>
    String(text ?? "")
        .split(/\n+/)
        .map((line) => line.trim())
        .filter(Boolean);

// --- live combat state (written back to the token) ---
const hpDelta = ref(0);
const applyDamage = () => {
    const amount = Math.max(0, Number(hpDelta.value) || 0);
    if (!amount) return;
    let temp = Number(props.tempHp) || 0;
    const absorbed = Math.min(temp, amount);
    temp -= absorbed;
    const newHp = Math.max(0, (Number(props.hp) || 0) - (amount - absorbed));
    // The server detects the damage (HP pool delta) and, if concentrating, posts the save prompt to chat
    // itself — so every damage path (this box, the tracker, the GM edit) triggers the check consistently.
    emit("update", { hp: newHp, temp_hp: temp });
    hpDelta.value = 0;
};
const applyHeal = () => {
    const amount = Math.max(0, Number(hpDelta.value) || 0);
    if (!amount) return;
    const ceiling = Number(props.maxHp) || (Number(props.hp) || 0) + amount;
    emit("update", { hp: Math.min(ceiling, (Number(props.hp) || 0) + amount) });
    hpDelta.value = 0;
};
const setTempHp = (event) =>
    emit("update", { temp_hp: Math.max(0, Number(event.target.value) || 0) });

// --- concentration ---
const concentrate = (spellName) =>
    emit("update", { concentrating_on: spellName });
const dropConcentration = () => emit("update", { concentrating_on: "" });
const dying = computed(() => (Number(props.hp) || 0) <= 0 && !!props.maxHp);
const setDeathSave = (type, n) => {
    if (!props.canEdit) return;
    const current = type === "success" ? props.deathSuccess : props.deathFail;
    const value = clamp(n === current ? n - 1 : n, 0, 3);
    emit("update", {
        [type === "success" ? "death_success" : "death_fail"]: value,
    });
};
const setExhaustion = (delta) =>
    emit("update", {
        exhaustion: clamp((Number(props.exhaustion) || 0) + delta, 0, 6),
    });

// --- hit dice + pact magic ---
const conMod = computed(() => abilityMod(stats.value.con));
const hitDicePools = computed(() =>
    (sheet.value.hit_dice ?? []).map((pool) => ({
        die: pool.die,
        total: pool.total,
        used: props.hitDiceUsed?.[pool.die] ?? pool.used ?? 0,
    })),
);
const pactGroup = computed(() =>
    sheet.value.pact_slots
        ? {
              level: "pact",
              label: "Pact Magic",
              slot: {
                  max: sheet.value.pact_slots.max,
                  used: sheet.value.pact_slots.used,
              },
          }
        : null,
);
const remainingHitDice = computed(() =>
    hitDicePools.value.reduce((sum, pool) => sum + (pool.total - pool.used), 0),
);
// Spend one hit die — rolled server-side so it heals and posts to chat authoritatively.
const spendHitDie = (pool) => {
    if (!props.canEdit || pool.used >= pool.total) return;
    emit("spend-hit-dice", { die: pool.die });
};

// Long rest: HP to max, temp cleared, every spell/pact slot refilled, death saves cleared, one level of
// exhaustion eased, and half your hit dice (rounded down, min 1) regained.
const longRest = () => {
    const slots = {};
    for (const group of sheet.value.spell_slots ?? []) slots[group.level] = 0;
    if (sheet.value.pact_slots) slots.pact = 0;
    const hitDice = {};
    for (const pool of hitDicePools.value) {
        hitDice[pool.die] = Math.max(
            0,
            pool.used - Math.max(1, Math.floor(pool.total / 2)),
        );
    }
    emit("update", {
        hp: props.maxHp ?? props.hp,
        temp_hp: 0,
        spell_slots_used: slots,
        hit_dice_used: hitDice,
        death_success: 0,
        death_fail: 0,
        exhaustion: Math.max(0, (Number(props.exhaustion) || 0) - 1),
    });
};
// Short rest: prompt for how many Hit Dice to spend (rolled server-side, posted to chat), then steady a
// downed character and refresh Warlock pact slots. Levelled slots need a long rest.
const restPrompt = reactive({ open: false, count: 1 });
const openShortRest = () => {
    restPrompt.count = Math.min(1, remainingHitDice.value) || 0;
    restPrompt.open = true;
};
const confirmShortRest = () => {
    const toSpend = clamp(
        Number(restPrompt.count) || 0,
        0,
        remainingHitDice.value,
    );
    if (toSpend > 0) emit("spend-hit-dice", { dice: toSpend });

    const patch = { death_success: 0, death_fail: 0 };
    if (sheet.value.pact_slots) {
        patch.spell_slots_used = { ...(props.slotsUsed || {}), pact: 0 };
    }
    emit("update", patch);
    restPrompt.open = false;
};

const sheet = computed(() => props.character?.sheet ?? {});
const stats = computed(() => props.character?.stats ?? {});
const ABILITIES = ["str", "dex", "con", "int", "wis", "cha"];
const abilityMod = (score) => Math.floor((Number(score ?? 10) - 10) / 2);
const signed = (n) => `${Number(n) >= 0 ? "+" : ""}${Number(n) || 0}`;
// 2024 exhaustion: every level is a flat −2 to all d20 Tests (checks, saves, attacks).
const exhaustionPenalty = computed(() => 2 * (Number(props.exhaustion) || 0));
// A d20 check/save/skill/attack roll string for the shared .dice-roll handler (e.g. "1d20+6"),
// with the current exhaustion penalty already folded in.
const rollFor = (modValue) =>
    `1d20${signed((Number(modValue) || 0) - exhaustionPenalty.value)}`;
// The dice portion of a damage string ("1d6 psychic" → "1d6", "1d6 - 1" → "1d6-1"); "" if none.
const diceExpr = (value) => {
    const match = String(value ?? "").match(/^[0-9d+\-\s]+/i);
    return match ? match[0].replace(/\s+/g, "") : "";
};

const subtitle = computed(() =>
    [
        props.character?.level ? `Level ${props.character.level}` : "",
        props.character?.race,
        props.character?.class,
    ]
        .filter(Boolean)
        .join(" · "),
);
const initial = (props.name || "?").charAt(0).toUpperCase();

const spellcasting = computed(() => sheet.value.spellcasting ?? null);
const slotsByLevel = computed(() =>
    Object.fromEntries(
        (sheet.value.spell_slots ?? []).map((s) => [s.level, s]),
    ),
);
const spellGroups = computed(() => {
    const groups = {};
    for (const spell of sheet.value.spells ?? []) {
        (groups[spell.level] ??= []).push(spell);
    }
    return Object.keys(groups)
        .map(Number)
        .sort((a, b) => a - b)
        .map((level) => ({
            level,
            label: level === 0 ? "Cantrips" : `Level ${level}`,
            slot: slotsByLevel.value[level],
            spells: groups[level],
        }));
});
const spellMeta = (sp) =>
    [sp.casting_time, sp.range, sp.components, sp.duration]
        .filter(Boolean)
        .join(" · ");
// Compact casting time ("1 bonus action" → "1BA", "10 minutes" → "10m") for the table's Time column.
const timeAbbr = (value) => {
    const match = String(value ?? "").match(
        /(\d+)\s*(bonus action|action|reaction|minute|hour|day)/i,
    );
    if (!match) return value ?? "";
    const units = {
        action: "A",
        "bonus action": "BA",
        reaction: "R",
        minute: "m",
        hour: "h",
        day: "d",
    };
    return `${match[1]}${units[match[2].toLowerCase()] ?? ""}`;
};
const rangeShort = (value) =>
    String(value ?? "")
        .replace(/\bfeet\b/i, "ft")
        .replace(/\bfoot\b/i, "ft");
const expandedSpells = ref(new Set());
const toggleSpell = (name) => {
    const next = new Set(expandedSpells.value);
    if (next.has(name)) next.delete(name);
    else next.add(name);
    expandedSpells.value = next;
};
// Live slot usage: the token's expenditure overrides the imported baseline.
const usedFor = (group) =>
    props.slotsUsed?.[group.level] ?? group.slot?.used ?? 0;
const clickSlot = (group, index) => {
    if (!props.canEdit || !group.slot) return;
    const max = group.slot.max;
    const available = max - usedFor(group);
    const newAvailable = index <= available ? index - 1 : index;
    emit("update", {
        spell_slots_used: {
            ...(props.slotsUsed || {}),
            [group.level]: clamp(max - newAvailable, 0, max),
        },
    });
};

// --- editable sheet state (prepared / equipped / attuned / currency) ---
// A player's choices are stored as overrides on the token so a Refresh re-pulls the D&D Beyond sheet
// without clobbering them. Each write merges into the existing sheet_state and emits the whole object.
const COINS = ["pp", "gp", "ep", "sp", "cp"];
const patchSheetState = (section, key, value) => {
    const base = props.sheetState || {};
    emit("update", {
        sheet_state: {
            ...base,
            [section]: { ...(base[section] || {}), [key]: value },
        },
    });
};
const preparedFor = (spell) =>
    props.sheetState?.prepared?.[spell.name] ?? !!spell.prepared;
const togglePrepared = (spell) => {
    if (!props.canEdit) return;
    patchSheetState("prepared", spell.name, !preparedFor(spell));
};
const equippedFor = (item) =>
    props.sheetState?.equipped?.[item.name] ?? !!item.equipped;
const attunedFor = (item) =>
    props.sheetState?.attuned?.[item.name] ?? !!item.attuned;
const toggleEquipped = (item) => {
    if (!props.canEdit) return;
    patchSheetState("equipped", item.name, !equippedFor(item));
};
const toggleAttuned = (item) => {
    if (!props.canEdit) return;
    patchSheetState("attuned", item.name, !attunedFor(item));
};

// Effective currency = the imported baseline with the token's overrides layered on top.
const currency = computed(() => ({
    ...(sheet.value.currency ?? {}),
    ...(props.sheetState?.currency ?? {}),
}));
const hasCurrency = computed(() =>
    COINS.some((k) => (currency.value[k] ?? 0) > 0),
);
const setCurrency = (coin, event) =>
    patchSheetState(
        "currency",
        coin,
        clamp(Number(event.target.value) || 0, 0, 9999999),
    );

// --- notes (owner / GM scratchpad, saved on blur) ---
const notesDraft = ref(props.notes);
watch(
    () => props.notes,
    (value) => {
        notesDraft.value = value;
    },
);
const saveNotes = () => {
    if (notesDraft.value !== props.notes)
        emit("update", { notes: notesDraft.value });
};

// Features grouped by source (Class / Race / Feat), like D&D Beyond, dropping level-tagged duplicates
// ("8: Ability Score Improvement") and pure character-builder boilerplate.
const FEATURE_NOISE = new Set([
    "Creature Type",
    "Size",
    "Speed",
    "Ability Score Increases",
]);
const featureGroups = computed(() => {
    const groups = {};
    for (const feature of sheet.value.features ?? []) {
        if (/^\d+:\s/.test(feature.name)) continue;
        if (FEATURE_NOISE.has(feature.name)) continue;
        const source = feature.source || "Other";
        (groups[source] ??= []).push(feature);
    }
    return Object.entries(groups).map(([source, features]) => ({
        source,
        features,
    }));
});

const descriptionEntries = computed(() => {
    const d = sheet.value.description ?? {};
    return [
        ["Background", d.background],
        ["Appearance", d.appearance],
        ["Personality", d.personality],
        ["Ideals", d.ideals],
        ["Bonds", d.bonds],
        ["Flaws", d.flaws],
        ["Backstory", d.backstory],
    ].filter(([, text]) => text);
});

const tabs = computed(() =>
    [
        {
            key: "actions",
            label: "Actions",
            show:
                (sheet.value.actions ?? []).length > 0 || !!spellcasting.value,
        },
        { key: "skills", label: "Skills", show: true },
        {
            key: "spells",
            label: "Spells",
            show: (sheet.value.spells ?? []).length > 0,
        },
        {
            key: "features",
            label: "Features",
            show: featureGroups.value.length > 0,
        },
        {
            key: "inventory",
            label: "Inventory",
            show:
                (sheet.value.inventory ?? []).length > 0 ||
                hasCurrency.value ||
                props.canEdit,
        },
        {
            key: "description",
            label: "Description",
            show: descriptionEntries.value.length > 0,
        },
        { key: "notes", label: "Notes", show: props.canEdit },
    ].filter((tab) => tab.show),
);
const activeTab = ref("skills");
watch(
    tabs,
    (list) => {
        if (!list.some((tab) => tab.key === activeTab.value)) {
            activeTab.value = list[0]?.key ?? "skills";
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="rounded-lg border border-edge2 bg-[#14161b] text-ink">
        <!-- Header -->
        <div class="flex items-center gap-3 p-4">
            <span
                class="h-14 w-14 shrink-0 overflow-hidden rounded-md bg-cover bg-center bg-raised font-display text-lg ring-1 ring-edge2"
                :style="{ backgroundImage: image ? `url(${image})` : 'none' }"
            >
                <span
                    v-if="!image"
                    class="flex h-full w-full items-center justify-center text-teal"
                    >{{ initial }}</span
                >
            </span>
            <div class="min-w-0 flex-1">
                <div class="font-display text-xl text-bright">{{ name }}</div>
                <div class="font-mono text-[11px] text-faint">
                    {{ subtitle }}
                </div>
            </div>
            <div class="flex gap-3 font-mono text-[11px] text-muted">
                <span v-if="ac !== null" class="text-center"
                    ><span class="block text-lg text-ink">{{ ac }}</span
                    >AC</span
                >
                <span v-if="maxHp" class="text-center"
                    ><span class="block text-lg text-[#9dc47a]"
                        >{{ hp ?? "?" }}/{{ maxHp
                        }}<span v-if="tempHp" class="text-teal">
                            +{{ tempHp }}</span
                        ></span
                    >HP</span
                >
                <span v-if="sheet.proficiency" class="text-center"
                    ><span class="block text-lg text-amber">{{
                        signed(sheet.proficiency)
                    }}</span
                    >Prof</span
                >
            </div>
        </div>

        <!-- Live HP management (owner / GM) -->
        <div
            v-if="canEdit && maxHp"
            class="mx-4 mt-1 flex flex-wrap items-center gap-2 rounded-md border border-edge2 bg-night px-2 py-1.5"
        >
            <input
                v-model="hpDelta"
                type="number"
                min="0"
                class="field !w-16 !py-1 text-sm"
                placeholder="0"
            />
            <button
                type="button"
                class="rounded bg-red-600/80 px-2 py-1 text-xs text-white hover:bg-red-600"
                @click="applyDamage"
            >
                Damage
            </button>
            <button
                type="button"
                class="rounded bg-[#5ea564]/80 px-2 py-1 text-xs text-white hover:bg-[#5ea564]"
                @click="applyHeal"
            >
                Heal
            </button>
            <label
                class="ml-auto flex items-center gap-1 text-[11px] text-faint"
                >Temp
                <input
                    :value="tempHp"
                    type="number"
                    min="0"
                    class="field !w-14 !py-1 text-sm"
                    @change="setTempHp"
            /></label>
            <div class="flex w-full items-center gap-2">
                <button
                    type="button"
                    class="rounded border border-edge3 px-2 py-1 text-xs text-muted hover:text-ink"
                    title="Spend Hit Dice to heal; refresh pact slots"
                    @click="openShortRest"
                >
                    Short rest
                </button>
                <button
                    type="button"
                    class="rounded border border-edge3 px-2 py-1 text-xs text-muted hover:text-ink"
                    title="Restore HP, spell slots and death saves; ease one level of exhaustion"
                    @click="longRest"
                >
                    Long rest
                </button>
                <div
                    class="ml-auto flex items-center gap-1.5 text-[11px] text-faint"
                >
                    <span>Exhaustion</span>
                    <button
                        type="button"
                        class="flex h-5 w-5 items-center justify-center rounded bg-night font-mono text-muted hover:text-ink"
                        @click="setExhaustion(-1)"
                    >
                        −
                    </button>
                    <span
                        class="w-4 text-center font-mono"
                        :class="exhaustion ? 'text-red-400' : 'text-ink'"
                        >{{ exhaustion }}</span
                    >
                    <button
                        type="button"
                        class="flex h-5 w-5 items-center justify-center rounded bg-night font-mono text-muted hover:text-ink"
                        @click="setExhaustion(1)"
                    >
                        +
                    </button>
                </div>
            </div>
            <div
                v-if="hitDicePools.length"
                class="flex w-full flex-wrap items-center gap-2 text-[11px] text-faint"
            >
                <span>Hit dice</span>
                <span
                    v-for="pool in hitDicePools"
                    :key="pool.die"
                    class="flex items-center gap-1.5"
                >
                    <span class="font-mono text-ink"
                        >{{ pool.total - pool.used }}/{{ pool.total }} d{{
                            pool.die
                        }}</span
                    >
                    <button
                        type="button"
                        class="rounded border border-edge3 px-1.5 py-0.5 text-[10px] text-muted hover:text-ink disabled:opacity-40"
                        :disabled="pool.used >= pool.total"
                        :title="`Roll 1d${pool.die} + ${conMod} to heal`"
                        @click="spendHitDie(pool)"
                    >
                        Spend
                    </button>
                </span>
            </div>
            <!-- Short-rest Hit Dice prompt -->
            <div
                v-if="restPrompt.open"
                class="flex w-full flex-wrap items-center gap-2 rounded border border-amber/30 bg-amber/5 px-2 py-1.5 text-[11px] text-faint"
            >
                <span>Spend how many Hit Dice?</span>
                <input
                    v-model="restPrompt.count"
                    type="number"
                    min="0"
                    :max="remainingHitDice"
                    class="field !w-14 !py-1 text-sm"
                />
                <span class="text-faint">of {{ remainingHitDice }}</span>
                <button
                    type="button"
                    class="rounded bg-[#5ea564]/80 px-2 py-1 text-xs text-white hover:bg-[#5ea564]"
                    @click="confirmShortRest"
                >
                    Roll
                </button>
                <button
                    type="button"
                    class="text-xs text-faint hover:text-ink"
                    @click="restPrompt.open = false"
                >
                    Cancel
                </button>
            </div>
        </div>

        <!-- Concentration (owner / GM). Damage triggers a save prompt in the chat, not here. -->
        <div
            v-if="canEdit && concentratingOn"
            class="mx-4 mt-1 flex flex-wrap items-center gap-2 rounded-md border border-teal/30 bg-night px-3 py-1.5 text-[11px]"
        >
            <span class="text-teal">Concentrating:</span>
            <span class="text-ink">{{ concentratingOn }}</span>
            <button
                type="button"
                class="text-faint hover:text-red-400"
                title="Drop concentration"
                @click="dropConcentration"
            >
                ✕
            </button>
        </div>

        <!-- Death saves (when down) -->
        <div
            v-if="canEdit && dying"
            class="mx-4 mt-1 flex items-center gap-4 rounded-md border border-red-500/30 bg-night px-3 py-1.5 text-[11px]"
        >
            <span class="text-faint">Death saves</span>
            <div class="flex items-center gap-1">
                <span class="text-[#5ea564]">✓</span>
                <button
                    v-for="n in 3"
                    :key="'s' + n"
                    type="button"
                    class="h-3.5 w-3.5 rounded-full"
                    :class="
                        n <= deathSuccess
                            ? 'bg-[#5ea564]'
                            : 'border border-edge3'
                    "
                    @click="setDeathSave('success', n)"
                ></button>
            </div>
            <div class="flex items-center gap-1">
                <span class="text-red-400">✗</span>
                <button
                    v-for="n in 3"
                    :key="'f' + n"
                    type="button"
                    class="h-3.5 w-3.5 rounded-full"
                    :class="
                        n <= deathFail ? 'bg-red-500' : 'border border-edge3'
                    "
                    @click="setDeathSave('fail', n)"
                ></button>
            </div>
        </div>

        <!-- Ability scores (pinned, click-to-roll) -->
        <div class="grid grid-cols-6 gap-1.5 px-4 text-center">
            <div
                v-for="a in ABILITIES"
                :key="a"
                class="dice-roll rounded-md border border-edge2 bg-night py-2"
                role="button"
                tabindex="0"
                :data-roll="rollFor(abilityMod(stats[a]))"
                :title="`Roll ${a.toUpperCase()} check`"
            >
                <div
                    class="font-mono text-[9px] uppercase tracking-[0.1em] text-faint"
                >
                    {{ a }}
                </div>
                <div class="font-display text-xl text-ink">
                    {{ signed(abilityMod(stats[a])) }}
                </div>
                <div class="font-mono text-[10px] text-faint">
                    {{ stats[a] ?? "—" }}
                </div>
            </div>
        </div>

        <!-- Tab bar -->
        <div class="mt-4 flex flex-wrap gap-1 border-b border-edge2 px-3">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                class="relative whitespace-nowrap px-3 py-2 font-mono text-[11px] uppercase tracking-[0.08em]"
                :class="
                    activeTab === tab.key
                        ? 'text-amber'
                        : 'text-faint hover:text-ink'
                "
                @click="activeTab = tab.key"
            >
                {{ tab.label }}
                <span
                    v-if="activeTab === tab.key"
                    class="absolute inset-x-0 -bottom-px h-0.5 bg-amber"
                ></span>
            </button>
        </div>

        <!-- Actions tab -->
        <div v-if="activeTab === 'actions'" class="space-y-3 p-4">
            <div
                v-if="spellcasting"
                class="flex flex-wrap gap-4 rounded-md border border-edge2 bg-night px-3 py-2 text-[12.5px]"
            >
                <span class="text-faint"
                    >Spellcasting
                    <span class="text-ink">{{
                        spellcasting.ability
                    }}</span></span
                >
                <span
                    class="dice-roll font-mono text-amber"
                    role="button"
                    tabindex="0"
                    :data-roll="rollFor(spellcasting.attack)"
                    title="Roll spell attack"
                    >Attack {{ signed(spellcasting.attack) }}</span
                >
                <span class="font-mono text-muted"
                    >Save DC {{ spellcasting.dc }}</span
                >
            </div>

            <table v-if="sheet.actions?.length" class="w-full text-[12.5px]">
                <thead>
                    <tr
                        class="text-left font-mono text-[10px] uppercase text-faint"
                    >
                        <th class="pb-1 font-normal">Attack</th>
                        <th class="pb-1 text-center font-normal">Hit / DC</th>
                        <th class="pb-1 text-right font-normal">Damage</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(a, i) in sheet.actions"
                        :key="a.name + i"
                        class="border-t border-edge2/60"
                    >
                        <td class="py-1.5">
                            <span class="text-ink">{{ a.name }}</span>
                            <span
                                class="ml-1 font-mono text-[10px] text-faint"
                                >{{ a.range }}</span
                            >
                        </td>
                        <td class="py-1.5 text-center font-mono">
                            <span
                                v-if="
                                    a.to_hit !== null && a.to_hit !== undefined
                                "
                                class="dice-roll text-amber"
                                role="button"
                                tabindex="0"
                                :data-roll="rollFor(a.to_hit)"
                                :title="`Roll ${a.name} attack`"
                                >{{ signed(a.to_hit) }}</span
                            >
                            <span v-else-if="a.save" class="text-muted"
                                >DC {{ a.save }}</span
                            >
                            <span v-else class="text-faint">—</span>
                        </td>
                        <td class="py-1.5 text-right font-mono">
                            <span
                                v-if="diceExpr(a.damage)"
                                class="dice-roll text-ink"
                                role="button"
                                tabindex="0"
                                :data-roll="diceExpr(a.damage)"
                                :title="`Roll ${a.name} damage`"
                                >{{ a.damage }}</span
                            >
                            <span v-else class="text-ink">{{ a.damage }}</span>
                            <span class="ml-1 text-[10px] text-faint">{{
                                a.damage_type
                            }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else-if="!spellcasting" class="text-[13px] text-faint">
                No attacks recorded.
            </p>
        </div>

        <!-- Skills tab: saves, senses, defenses, skills -->
        <div v-else-if="activeTab === 'skills'" class="space-y-4 p-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <div class="eyebrow-muted mb-1.5">Saving throws</div>
                    <div class="flex flex-wrap gap-1.5">
                        <span
                            v-for="s in sheet.saves || []"
                            :key="s.ability"
                            class="dice-roll rounded border px-2 py-0.5 font-mono text-[11px]"
                            role="button"
                            tabindex="0"
                            :data-roll="rollFor(s.mod)"
                            :title="`Roll ${s.ability} save`"
                            :class="
                                s.proficient
                                    ? 'border-amber/50 bg-amber/10 text-amber'
                                    : 'border-edge3 text-muted'
                            "
                            >{{ s.ability }} {{ signed(s.mod) }}</span
                        >
                    </div>
                </div>
                <div v-if="sheet.senses || sheet.languages?.length">
                    <div class="eyebrow-muted mb-1.5">
                        Senses &amp; languages
                    </div>
                    <div class="text-[12px] text-muted">{{ sheet.senses }}</div>
                    <div
                        v-if="sheet.languages?.length"
                        class="text-[12px] text-muted"
                    >
                        {{ sheet.languages.join(", ") }}
                    </div>
                </div>
            </div>

            <div
                v-if="
                    sheet.defenses &&
                    (sheet.defenses.resistances?.length ||
                        sheet.defenses.immunities?.length ||
                        sheet.defenses.vulnerabilities?.length)
                "
                class="space-y-0.5 text-[12px]"
            >
                <div v-if="sheet.defenses.resistances?.length">
                    <span class="text-faint">Resistances:</span>
                    {{ sheet.defenses.resistances.join(", ") }}
                </div>
                <div v-if="sheet.defenses.immunities?.length">
                    <span class="text-faint">Immunities:</span>
                    {{ sheet.defenses.immunities.join(", ") }}
                </div>
                <div v-if="sheet.defenses.vulnerabilities?.length">
                    <span class="text-faint">Vulnerabilities:</span>
                    {{ sheet.defenses.vulnerabilities.join(", ") }}
                </div>
            </div>

            <div v-if="sheet.skills?.length">
                <div class="eyebrow-muted mb-1.5">Skills</div>
                <div class="grid gap-x-4 gap-y-0.5 sm:grid-cols-2">
                    <div
                        v-for="s in sheet.skills"
                        :key="s.label"
                        class="dice-roll flex items-center justify-between rounded px-1 py-0.5 text-[12.5px]"
                        role="button"
                        tabindex="0"
                        :data-roll="rollFor(s.mod)"
                        :title="`Roll ${s.label}`"
                        :class="s.proficient ? 'text-ink' : 'text-muted'"
                    >
                        <span
                            ><span
                                class="mr-1.5 inline-block h-1.5 w-1.5 rounded-full"
                                :class="s.proficient ? 'bg-amber' : 'bg-edge3'"
                            ></span
                            >{{ s.label }}
                            <span class="text-faint"
                                >({{ s.ability }})</span
                            ></span
                        >
                        <span class="font-mono">{{ signed(s.mod) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Spells tab (table: casting time / range / hit-dc / effect at a glance) -->
        <div v-else-if="activeTab === 'spells'" class="p-4">
            <table class="w-full text-[12px]">
                <thead>
                    <tr
                        class="text-left font-mono text-[9px] uppercase tracking-[0.06em] text-faint"
                    >
                        <th class="pb-1 pr-1"></th>
                        <th class="pb-1">Name</th>
                        <th class="pb-1">Time</th>
                        <th class="pb-1">Range</th>
                        <th class="pb-1">Hit / DC</th>
                        <th class="pb-1">Effect</th>
                        <th class="pb-1">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Pact magic resource row -->
                    <tr v-if="pactGroup">
                        <td colspan="7" class="pt-2">
                            <div
                                class="flex items-center justify-between rounded-md border border-amber/30 bg-amber/5 px-2 py-1"
                            >
                                <span class="eyebrow-muted"
                                    >{{ pactGroup.label }} · Lv
                                    {{ sheet.pact_slots.level }}</span
                                >
                                <span
                                    class="flex items-center gap-1 font-mono text-[10px] text-faint"
                                >
                                    <button
                                        v-for="n in pactGroup.slot.max"
                                        :key="n"
                                        type="button"
                                        class="inline-block h-2.5 w-2.5 rounded-full"
                                        :class="[
                                            n <=
                                            pactGroup.slot.max -
                                                usedFor(pactGroup)
                                                ? 'bg-amber'
                                                : 'border border-edge3 bg-transparent',
                                            canEdit
                                                ? 'cursor-pointer'
                                                : 'cursor-default',
                                        ]"
                                        :disabled="!canEdit"
                                        @click="clickSlot(pactGroup, n)"
                                    ></button>
                                    <span class="ml-1"
                                        >{{
                                            pactGroup.slot.max -
                                            usedFor(pactGroup)
                                        }}/{{ pactGroup.slot.max }}</span
                                    >
                                </span>
                            </div>
                        </td>
                    </tr>

                    <template v-for="group in spellGroups" :key="group.level">
                        <tr>
                            <td colspan="7" class="pb-1 pt-3">
                                <div class="flex items-center justify-between">
                                    <span class="eyebrow-muted">{{
                                        group.label
                                    }}</span>
                                    <span
                                        v-if="group.slot"
                                        class="flex items-center gap-1 font-mono text-[10px] text-faint"
                                    >
                                        <button
                                            v-for="n in group.slot.max"
                                            :key="n"
                                            type="button"
                                            class="inline-block h-2.5 w-2.5 rounded-full"
                                            :class="[
                                                n <=
                                                group.slot.max - usedFor(group)
                                                    ? 'bg-amber'
                                                    : 'border border-edge3 bg-transparent',
                                                canEdit
                                                    ? 'cursor-pointer'
                                                    : 'cursor-default',
                                            ]"
                                            :disabled="!canEdit"
                                            :title="
                                                canEdit ? 'Toggle slot' : ''
                                            "
                                            @click="clickSlot(group, n)"
                                        ></button>
                                        <span class="ml-1"
                                            >{{
                                                group.slot.max - usedFor(group)
                                            }}/{{ group.slot.max }}</span
                                        >
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <template v-for="sp in group.spells" :key="sp.name">
                            <tr class="border-t border-edge2/60 align-top">
                                <td class="py-1.5 pr-1">
                                    <button
                                        type="button"
                                        class="inline-block h-2.5 w-2.5 rounded-full align-middle"
                                        :class="[
                                            preparedFor(sp)
                                                ? 'bg-amber'
                                                : 'bg-edge3',
                                            canEdit
                                                ? 'cursor-pointer hover:ring-2 hover:ring-amber/40'
                                                : 'cursor-default',
                                        ]"
                                        :disabled="!canEdit"
                                        :title="
                                            canEdit
                                                ? preparedFor(sp)
                                                    ? 'Prepared — click to unprepare'
                                                    : 'Known — click to prepare'
                                                : preparedFor(sp)
                                                  ? 'Prepared'
                                                  : 'Known'
                                        "
                                        @click="togglePrepared(sp)"
                                    ></button>
                                </td>
                                <td class="py-1.5">
                                    <button
                                        type="button"
                                        class="text-left text-ink hover:text-amber"
                                        @click="toggleSpell(sp.name)"
                                    >
                                        {{ sp.name }}
                                    </button>
                                    <span
                                        v-if="sp.ritual"
                                        class="ml-1 font-mono text-[9px] text-teal"
                                        title="Ritual"
                                        >R</span
                                    >
                                </td>
                                <td class="py-1.5 font-mono text-[11px]">
                                    {{ timeAbbr(sp.casting_time) }}
                                </td>
                                <td
                                    class="py-1.5 font-mono text-[11px] text-muted"
                                >
                                    {{ rangeShort(sp.range) }}
                                </td>
                                <td class="py-1.5 font-mono text-[11px]">
                                    <span
                                        v-if="sp.attack && spellcasting"
                                        class="dice-roll text-amber"
                                        role="button"
                                        tabindex="0"
                                        :data-roll="
                                            rollFor(spellcasting.attack)
                                        "
                                        title="Roll spell attack"
                                        @click.stop
                                        >{{ signed(spellcasting.attack) }}</span
                                    >
                                    <span v-else-if="sp.save" class="text-muted"
                                        >{{
                                            spellcasting
                                                ? `DC ${spellcasting.dc}`
                                                : "DC"
                                        }}
                                        {{ sp.save }}</span
                                    >
                                    <span v-else class="text-faint">—</span>
                                </td>
                                <td class="py-1.5 font-mono text-[11px]">
                                    <span
                                        v-if="diceExpr(sp.damage)"
                                        class="dice-roll text-ink"
                                        role="button"
                                        tabindex="0"
                                        :data-roll="diceExpr(sp.damage)"
                                        title="Roll spell damage"
                                        @click.stop
                                        >{{ sp.damage }}</span
                                    >
                                    <span v-else class="text-faint">—</span>
                                </td>
                                <td
                                    class="py-1.5 font-mono text-[10px] text-faint"
                                >
                                    {{ sp.components
                                    }}<span v-if="sp.concentration">
                                        · Conc</span
                                    >
                                </td>
                            </tr>
                            <tr v-if="expandedSpells.has(sp.name)">
                                <td></td>
                                <td
                                    colspan="6"
                                    class="pb-2 text-[12px] text-muted"
                                >
                                    <div
                                        class="flex items-center gap-2 font-mono text-[10px] text-faint"
                                    >
                                        <span>{{ spellMeta(sp) }}</span>
                                        <button
                                            v-if="sp.concentration && canEdit"
                                            type="button"
                                            class="rounded border border-teal/50 px-1.5 py-0.5 text-teal hover:bg-teal/10"
                                            :class="{
                                                'bg-teal/15':
                                                    concentratingOn === sp.name,
                                            }"
                                            @click="concentrate(sp.name)"
                                        >
                                            {{
                                                concentratingOn === sp.name
                                                    ? "Concentrating"
                                                    : "Concentrate"
                                            }}
                                        </button>
                                    </div>
                                    <div
                                        v-if="sp.description"
                                        class="mt-1 space-y-1.5 leading-relaxed"
                                    >
                                        <p
                                            v-for="(para, i) in paras(
                                                sp.description,
                                            )"
                                            :key="i"
                                        >
                                            {{ para }}
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Features tab (grouped by source) -->
        <div v-else-if="activeTab === 'features'" class="space-y-4 p-4">
            <div v-for="group in featureGroups" :key="group.source">
                <div class="eyebrow-muted mb-1">{{ group.source }}</div>
                <details
                    v-for="f in group.features"
                    :key="f.name"
                    class="border-t border-edge2/60"
                >
                    <summary
                        class="flex cursor-pointer list-none items-center py-1.5 text-[12.5px] marker:content-none"
                    >
                        <span class="font-semibold text-ink">{{ f.name }}</span>
                        <span
                            v-if="f.desc"
                            class="ml-auto font-mono text-[10px] text-faint"
                            >▾</span
                        >
                    </summary>
                    <div
                        v-if="f.desc"
                        class="space-y-1.5 pb-2 text-[12px] leading-relaxed text-muted"
                    >
                        <p v-for="(para, i) in paras(f.desc)" :key="i">
                            {{ para }}
                        </p>
                    </div>
                </details>
            </div>
        </div>

        <!-- Inventory tab -->
        <div v-else-if="activeTab === 'inventory'" class="p-4">
            <!-- Currency: editable coin fields for the owner/GM, else a compact readout. -->
            <div
                v-if="canEdit"
                class="mb-3 flex flex-wrap gap-2 font-mono text-[11px]"
            >
                <label
                    v-for="coin in COINS"
                    :key="coin"
                    class="flex items-center gap-1 text-faint"
                >
                    <input
                        :value="currency[coin] ?? 0"
                        type="number"
                        min="0"
                        class="field !w-16 !py-1 text-right text-sm"
                        @change="setCurrency(coin, $event)"
                    />{{ coin }}
                </label>
            </div>
            <div
                v-else-if="hasCurrency"
                class="mb-3 flex flex-wrap gap-3 font-mono text-[11px] text-muted"
            >
                <span v-for="coin in COINS" :key="coin">
                    <template v-if="currency[coin]"
                        ><span class="text-ink">{{ currency[coin] }}</span>
                        {{ coin }}</template
                    >
                </span>
            </div>
            <div class="space-y-0.5">
                <div
                    v-for="(it, i) in sheet.inventory"
                    :key="it.name + i"
                    class="flex items-center justify-between gap-2 text-[12.5px]"
                    :title="it.description"
                >
                    <span class="min-w-0 truncate">
                        <span :class="it.magic ? 'text-amber' : 'text-ink'">{{
                            it.name
                        }}</span>
                        <span v-if="it.quantity > 1" class="text-faint">
                            ×{{ it.quantity }}</span
                        >
                        <template v-if="canEdit">
                            <button
                                type="button"
                                class="ml-1.5 rounded border px-1 py-px font-mono text-[9px]"
                                :class="
                                    equippedFor(it)
                                        ? 'border-teal/50 bg-teal/10 text-teal'
                                        : 'border-edge3 text-faint hover:text-ink'
                                "
                                :title="
                                    equippedFor(it)
                                        ? 'Equipped — click to unequip'
                                        : 'Click to equip'
                                "
                                @click="toggleEquipped(it)"
                            >
                                equipped
                            </button>
                            <button
                                type="button"
                                class="ml-1 rounded border px-1 py-px font-mono text-[9px]"
                                :class="
                                    attunedFor(it)
                                        ? 'border-teal/50 bg-teal/10 text-teal'
                                        : 'border-edge3 text-faint hover:text-ink'
                                "
                                :title="
                                    attunedFor(it)
                                        ? 'Attuned — click to break attunement'
                                        : 'Click to attune'
                                "
                                @click="toggleAttuned(it)"
                            >
                                attuned
                            </button>
                        </template>
                        <template v-else>
                            <span
                                v-if="equippedFor(it)"
                                class="ml-1 font-mono text-[9px] text-teal"
                                >equipped</span
                            >
                            <span
                                v-if="attunedFor(it)"
                                class="ml-1 font-mono text-[9px] text-teal"
                                >attuned</span
                            >
                        </template>
                    </span>
                    <span class="shrink-0 font-mono text-[10px] text-faint"
                        >{{ it.rarity || it.type
                        }}{{ it.weight ? ` · ${it.weight} lb` : "" }}</span
                    >
                </div>
            </div>
        </div>

        <!-- Notes tab (owner / GM scratchpad) -->
        <div v-else-if="activeTab === 'notes'" class="p-4">
            <textarea
                v-model="notesDraft"
                rows="10"
                class="field w-full text-[13px] leading-relaxed"
                placeholder="Private notes for this character…"
                @blur="saveNotes"
            ></textarea>
            <p class="mt-1.5 font-mono text-[10px] text-faint">
                Saved when you click away. Visible only to you and the GM.
            </p>
        </div>

        <!-- Description tab -->
        <div v-else-if="activeTab === 'description'" class="space-y-3 p-4">
            <div v-for="[label, text] in descriptionEntries" :key="label">
                <div class="eyebrow-muted mb-0.5">{{ label }}</div>
                <div
                    class="space-y-1.5 text-[12.5px] leading-relaxed text-muted"
                >
                    <p v-for="(para, i) in paras(text)" :key="i">{{ para }}</p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Click-to-roll targets: abilities, saves, skills, attacks and spells. The room detail modal's
   @click.capture handler picks these up (GM logs privately, Ctrl shares, Shift/Alt = adv/disadv). */
.dice-roll {
    cursor: pointer;
}
.dice-roll:hover {
    background: rgba(216, 169, 74, 0.14);
    color: #f0e6d2;
}
</style>
