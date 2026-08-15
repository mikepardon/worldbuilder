<script setup>
// Compact editor for a structured stat block (the same `block` shape the compendium uses). It mutates
// the passed block object in place, so a StatblockCard bound to the same object previews edits live.
const props = defineProps({
    block: { type: Object, required: true },
});

const abilities = ["str", "dex", "con", "int", "wis", "cha"];
const groups = [
    { key: "traits", label: "Traits" },
    { key: "actions", label: "Actions" },
    { key: "bonusActions", label: "Bonus actions" },
    { key: "reactions", label: "Reactions" },
    { key: "legendary", label: "Legendary actions" },
];

const addRow = (key) => {
    if (!Array.isArray(props.block[key])) props.block[key] = [];
    props.block[key].push({ name: "", desc: "" });
};
const removeRow = (key, index) => props.block[key].splice(index, 1);
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="grid grid-cols-3 gap-2">
            <label class="text-xs text-muted">Size<input v-model="block.size" class="field !py-1 text-sm" /></label>
            <label class="text-xs text-muted">Type<input v-model="block.type" class="field !py-1 text-sm" /></label>
            <label class="text-xs text-muted">Alignment<input v-model="block.alignment" class="field !py-1 text-sm" /></label>
        </div>
        <div class="grid grid-cols-4 gap-2">
            <label class="text-xs text-muted">AC<input v-model="block.ac" class="field !py-1 text-sm" /></label>
            <label class="text-xs text-muted">HP<input v-model="block.hp" class="field !py-1 text-sm" /></label>
            <label class="text-xs text-muted">Speed<input v-model="block.speed" class="field !py-1 text-sm" /></label>
            <label class="text-xs text-muted">CR<input v-model="block.cr" class="field !py-1 text-sm" /></label>
        </div>

        <!-- Abilities -->
        <div class="grid grid-cols-6 gap-1.5">
            <label v-for="ability in abilities" :key="ability" class="text-center text-[10px] uppercase text-faint">
                {{ ability }}
                <input v-model.number="block.abilities[ability]" type="number" min="1" max="30" class="field !px-1 !py-1 text-center text-sm" />
            </label>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <label class="text-xs text-muted">Saves<input v-model="block.saves" class="field !py-1 text-sm" /></label>
            <label class="text-xs text-muted">Skills<input v-model="block.skills" class="field !py-1 text-sm" /></label>
            <label class="text-xs text-muted">Senses<input v-model="block.senses" class="field !py-1 text-sm" /></label>
            <label class="text-xs text-muted">Languages<input v-model="block.languages" class="field !py-1 text-sm" /></label>
            <label class="text-xs text-muted">Resistances<input v-model="block.resistances" class="field !py-1 text-sm" /></label>
            <label class="text-xs text-muted">Immunities<input v-model="block.immunities" class="field !py-1 text-sm" /></label>
        </div>

        <!-- Action groups -->
        <div v-for="group in groups" :key="group.key" class="flex flex-col gap-1.5">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-muted">{{ group.label }}</span>
                <button type="button" class="text-xs text-faint hover:text-amber" @click="addRow(group.key)">+ Add</button>
            </div>
            <div v-for="(row, index) in block[group.key] ?? []" :key="index" class="flex items-start gap-2">
                <input v-model="row.name" class="field !w-40 !py-1 text-sm" placeholder="Name" />
                <textarea v-model="row.desc" rows="2" class="field flex-1 !py-1 text-sm" placeholder="Description"></textarea>
                <button type="button" class="mt-1 text-faint hover:text-red-400" @click="removeRow(group.key, index)">✕</button>
            </div>
        </div>
    </div>
</template>
