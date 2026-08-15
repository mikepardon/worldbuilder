<script setup>
import { useCredits } from "@/composables/useCredits";
import { computed } from "vue";

// A small "✦ Costs N credits" hint shown next to an AI action button. Pass an explicit `credits`
// amount, or a `kind` (npc, monster, …) / `action` (assistant_chat, roll_table, …) to look the cost
// up from the shared schedule. Use `approx` for costs that depend on input (e.g. recaps by length).
const {
    credits = undefined,
    kind = undefined,
    action = undefined,
    prefix = "Costs",
    approx = false,
} = defineProps({
    credits: { type: Number, default: undefined },
    kind: { type: String, default: undefined },
    action: { type: String, default: undefined },
    prefix: { type: String, default: "Costs" },
    approx: { type: Boolean, default: false },
});

const { costForKind, costForAction } = useCredits();

const amount = computed(() => {
    if (credits !== undefined) {
        return credits;
    }
    if (kind !== undefined) {
        return costForKind(kind);
    }
    if (action !== undefined) {
        return costForAction(action);
    }
    return 1;
});

const label = computed(
    () => `${amount.value} ${amount.value === 1 ? "credit" : "credits"}`,
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 font-mono text-[10px] uppercase tracking-wider text-faint"
    >
        <span class="text-teal" aria-hidden="true">✦</span>
        {{ prefix }} {{ approx ? "~" : "" }}{{ label }}
    </span>
</template>
