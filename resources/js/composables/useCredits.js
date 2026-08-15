import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";

/**
 * Reads the signed-in GM's AI credit standing and per-action cost schedule, shared globally by
 * HandleInertiaRequests as `credits`. Module-scoped so every component reads the same reactive source;
 * the values refresh whenever Inertia updates shared props (e.g. after an AI action spends credits).
 *
 * Daily free credits are always spent before the purchased top-up balance, so `remaining` is
 * `dailyLeft + balance`.
 */
export function useCredits() {
    const page = usePage();
    const credits = computed(() => page.props.credits ?? null);

    const remaining = computed(() => credits.value?.remaining ?? 0);
    const dailyAllowance = computed(() => credits.value?.daily_allowance ?? 0);
    const dailyUsed = computed(() => credits.value?.daily_used ?? 0);
    const dailyLeft = computed(() =>
        Math.max(0, dailyAllowance.value - dailyUsed.value),
    );
    const balance = computed(() => credits.value?.balance ?? 0);
    const recapPerHour = computed(
        () => credits.value?.costs?.actions?.recap_per_hour ?? 0,
    );

    /** Credits an entry/generator of the given raw kind (npc, monster, …) costs. */
    const costForKind = (kind) => credits.value?.costs?.kinds?.[kind] ?? 1;

    /** Credits a fixed-cost action (assistant_chat, session_writeup, roll_table, bloodline) costs. */
    const costForAction = (action) =>
        credits.value?.costs?.actions?.[action] ?? 1;

    return {
        credits,
        remaining,
        dailyAllowance,
        dailyUsed,
        dailyLeft,
        balance,
        recapPerHour,
        costForKind,
        costForAction,
    };
}
